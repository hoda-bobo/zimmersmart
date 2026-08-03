<?php

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

include "connection.php";
include "language.php";

$user_id = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$location = trim($_GET['location'] ?? '');

$guests = filter_var(
    $_GET['guests'] ?? '',
    FILTER_VALIDATE_INT
);

$max_price = filter_var(
    $_GET['max_price'] ?? '',
    FILTER_VALIDATE_INT
);

$selected_services = $_GET['services'] ?? [];

if (!is_array($selected_services)) {
    $selected_services = [];
}

$selected_services = array_map(
    'intval',
    $selected_services
);

/*
|--------------------------------------------------------------------------
| Save search activity
|--------------------------------------------------------------------------
*/

if (
    $location !== '' ||
    $guests ||
    $max_price ||
    !empty($selected_services)
) {

    $activity_stmt = $conn->prepare("
        INSERT INTO user_activity
        (
            user_id,
            action_type,
            search_location,
            guests,
            max_price
        )
        VALUES
        (
            ?,
            'search',
            ?,
            ?,
            ?
        )
    ");

    if ($activity_stmt) {

        $activity_guests = $guests ?: 0;
        $activity_price = $max_price ?: 0;

        $activity_stmt->bind_param(
            "isii",
            $user_id,
            $location,
            $activity_guests,
            $activity_price
        );

        $activity_stmt->execute();
        $activity_stmt->close();
    }

    /*
    |--------------------------------------------------------------------------
    | Save lead
    |--------------------------------------------------------------------------
    */

    $notes = t('lead_search_no_booking_note');
    $lead_type = "searched_no_booking";

    $lead_stmt = $conn->prepare("
        INSERT INTO leads
        (
            user_id,
            cabin_id,
            lead_type,
            notes
        )
        VALUES
        (
            ?,
            NULL,
            ?,
            ?
        )
    ");

    if ($lead_stmt) {

        $lead_stmt->bind_param(
            "iss",
            $user_id,
            $lead_type,
            $notes
        );

        $lead_stmt->execute();
        $lead_stmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| Save searched services
|--------------------------------------------------------------------------
*/

if (!empty($selected_services)) {

    foreach ($selected_services as $service_id) {

        $log_stmt = $conn->prepare("
            INSERT INTO search_logs
            (
                user_id,
                service_id
            )
            VALUES
            (
                ?,
                ?
            )
        ");

        if ($log_stmt) {

            $log_stmt->bind_param(
                "ii",
                $user_id,
                $service_id
            );

            $log_stmt->execute();
            $log_stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Locations
|--------------------------------------------------------------------------
*/

$locations_result = $conn->query("
    SELECT DISTINCT location
    FROM cabins
    WHERE location IS NOT NULL
      AND location != ''
    ORDER BY location
");

/*
|--------------------------------------------------------------------------
| Services
|--------------------------------------------------------------------------
*/

$services_array = [];

$services_result = $conn->query("
    SELECT
        service_id,
        service_name
    FROM services
    ORDER BY service_name
");

if ($services_result) {

    while ($service = $services_result->fetch_assoc()) {
        $services_array[] = $service;
    }
}

/*
|--------------------------------------------------------------------------
| Main search query
|--------------------------------------------------------------------------
*/

$query = "
    SELECT
        c.*,

        (
            SELECT image_path
            FROM cabin_images
            WHERE cabin_id = c.id
            ORDER BY id ASC
            LIMIT 1
        ) AS main_image

    FROM cabins c

    WHERE 1 = 1
";

$params = [];
$types = "";

if ($location !== '') {

    $query .= " AND c.location = ?";

    $params[] = $location;
    $types .= "s";
}

if ($guests) {

    $query .= " AND c.max_guests >= ?";

    $params[] = $guests;
    $types .= "i";
}

if ($max_price) {

    $query .= " AND c.price_per_night <= ?";

    $params[] = $max_price;
    $types .= "i";
}

if (!empty($selected_services)) {

    $placeholders = implode(
        ',',
        array_fill(
            0,
            count($selected_services),
            '?'
        )
    );

    $query .= "
        AND c.id IN
        (
            SELECT cabin_id

            FROM cabin_services

            WHERE service_id IN ($placeholders)

            GROUP BY cabin_id

            HAVING COUNT(DISTINCT service_id) = ?
        )
    ";

    foreach ($selected_services as $service_id) {

        $params[] = $service_id;
        $types .= "i";
    }

    $params[] = count($selected_services);
    $types .= "i";
}

$query .= " ORDER BY c.id DESC";

$stmt = $conn->prepare($query);

if (!$stmt) {
    die("Search query error: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();
$total = $result->num_rows;

/*
|--------------------------------------------------------------------------
| Smart alternative search
|--------------------------------------------------------------------------
*/

$is_alternative = false;
$alt_stmt = null;

if ($total === 0) {

    $service_placeholders = "";

    if (!empty($selected_services)) {

        $service_placeholders = implode(
            ',',
            array_fill(
                0,
                count($selected_services),
                '?'
            )
        );
    }

    $alt_query = "
        SELECT
            c.*,

            (
                SELECT image_path
                FROM cabin_images
                WHERE cabin_id = c.id
                ORDER BY id ASC
                LIMIT 1
            ) AS main_image,

            (
                CASE
                    WHEN LOWER(TRIM(c.location)) =
                         LOWER(TRIM(?))
                    THEN 30
                    ELSE 0
                END

                +

                CASE
                    WHEN c.max_guests >= ?
                    THEN 20
                    ELSE 0
                END

                +

                CASE
                    WHEN c.price_per_night <= ?
                    THEN 20
                    ELSE 0
                END
    ";

    if (!empty($selected_services)) {

        $alt_query .= "
                +

                (
                    SELECT COUNT(*) * 10

                    FROM cabin_services cs

                    WHERE cs.cabin_id = c.id

                      AND cs.service_id IN
                      ($service_placeholders)
                )
        ";
    }

    $alt_query .= "
                +

                (
                    SELECT COUNT(*)

                    FROM bookings b

                    WHERE b.cabin_id = c.id

                      AND b.status = 'confirmed'
                ) * 2

            ) AS score

        FROM cabins c

        ORDER BY
            score DESC,
            c.id DESC

        LIMIT 6
    ";

    $alt_stmt = $conn->prepare($alt_query);

    if (!$alt_stmt) {
        die("Alternative search error: " . $conn->error);
    }

    $alt_types = "sii";

    $alt_params = [
        $location,
        $guests ?: 0,
        $max_price ?: 999999
    ];

    if (!empty($selected_services)) {

        foreach ($selected_services as $service_id) {

            $alt_params[] = $service_id;
            $alt_types .= "i";
        }
    }

    $alt_stmt->bind_param(
        $alt_types,
        ...$alt_params
    );

    $alt_stmt->execute();

    $result = $alt_stmt->get_result();
    $total = $result->num_rows;

    $is_alternative = true;
}

?>

<!DOCTYPE html>

<html
    lang="<?= current_language() ?>"
    dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>"
>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars(t('search')) ?>
    </title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

</head>

<body>

<?php include "navbar.php"; ?>

<main class="search-page">

    <div class="search-container">

        <section class="search-heading">

            <h1>
                <?= htmlspecialchars(
                    t('find_your_perfect_cabin')
                ) ?>
            </h1>

        </section>

        <form
            method="GET"
            action="search.php"
            class="search-box"
        >

            <div class="search-main-filters">

                <div class="search-filter-field">

                    <select
                        name="location"
                        aria-label="<?= htmlspecialchars(
                            t('all_locations')
                        ) ?>"
                    >

                        <option value="">
                            <?= htmlspecialchars(
                                t('all_locations')
                            ) ?>
                        </option>

                        <?php while (
                            $loc = $locations_result->fetch_assoc()
                        ): ?>

                            <option
                                value="<?= htmlspecialchars(
                                    $loc['location']
                                ) ?>"
                                <?= $location === $loc['location']
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= htmlspecialchars(
                                    $loc['location']
                                ) ?>
                            </option>

                        <?php endwhile; ?>

                    </select>

                </div>

                <div class="search-filter-field">

                    <select
                        name="guests"
                        aria-label="<?= htmlspecialchars(
                            t('guests')
                        ) ?>"
                    >

                        <option value="">
                            <?= htmlspecialchars(t('guests')) ?>
                        </option>

                        <option
                            value="2"
                            <?= $guests === 2
                                ? 'selected'
                                : ''
                            ?>
                        >
                            2
                        </option>

                        <option
                            value="4"
                            <?= $guests === 4
                                ? 'selected'
                                : ''
                            ?>
                        >
                            4
                        </option>

                        <option
                            value="6"
                            <?= $guests === 6
                                ? 'selected'
                                : ''
                            ?>
                        >
                            6
                        </option>

                        <option
                            value="8"
                            <?= $guests === 8
                                ? 'selected'
                                : ''
                            ?>
                        >
                            8+
                        </option>

                    </select>

                </div>

                <div class="search-filter-field">

                    <select
                        name="max_price"
                        aria-label="<?= htmlspecialchars(
                            t('max_price')
                        ) ?>"
                    >

                        <option value="">
                            <?= htmlspecialchars(t('max_price')) ?>
                        </option>

                        <option
                            value="200"
                            <?= $max_price === 200
                                ? 'selected'
                                : ''
                            ?>
                        >
                            ₪200
                        </option>

                        <option
                            value="400"
                            <?= $max_price === 400
                                ? 'selected'
                                : ''
                            ?>
                        >
                            ₪400
                        </option>

                        <option
                            value="600"
                            <?= $max_price === 600
                                ? 'selected'
                                : ''
                            ?>
                        >
                            ₪600
                        </option>

                        <option
                            value="800"
                            <?= $max_price === 800
                                ? 'selected'
                                : ''
                            ?>
                        >
                            ₪800
                        </option>

                    </select>

                </div>

                <button
                    type="submit"
                    class="search-submit-button"
                >
                    <?= htmlspecialchars(t('search')) ?>
                </button>

            </div>

            <?php if (!empty($services_array)): ?>

                <div class="services-filter">

                    <?php foreach (
                        $services_array as $service
                    ): ?>

                        <?php

                        $service_id =
                            (int) $service['service_id'];

                        $is_checked = in_array(
                            $service_id,
                            $selected_services,
                            true
                        );

                        ?>

                        <label class="chip">

                            <input
                                type="checkbox"
                                name="services[]"
                                value="<?= $service_id ?>"
                                <?= $is_checked
                                    ? 'checked'
                                    : ''
                                ?>
                            >

                            <span>
                                <?= htmlspecialchars(
                                    $service['service_name']
                                ) ?>
                            </span>

                        </label>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </form>

        <?php if ($is_alternative): ?>

            <div class="alt-box">

                <strong>
                    <?= htmlspecialchars(
                        t('no_exact_matches')
                    ) ?>
                </strong>

                <span>
                    <?= htmlspecialchars(
                        t('showing_closest_options')
                    ) ?>
                </span>

            </div>

        <?php endif; ?>

        <div class="search-results-heading">

            <h2>

                <?php if ($is_alternative): ?>

                    <?= htmlspecialchars(
                        t('recommended_results')
                    ) ?>

                <?php else: ?>

                    <?= htmlspecialchars(
                        sprintf(
                            t('results_count'),
                            $total
                        )
                    ) ?>

                <?php endif; ?>

            </h2>

        </div>

        <div class="search-cards">

            <?php while (
                $cabin = $result->fetch_assoc()
            ): ?>

                <article class="search-cabin-card">

                    <div class="search-cabin-image-wrapper">

                        <img
                            src="<?= htmlspecialchars(
                                !empty($cabin['main_image'])
                                    ? $cabin['main_image']
                                    : 'uploads/default.jpg'
                            ) ?>"
                            alt="<?= htmlspecialchars(
                                $cabin['name']
                            ) ?>"
                            class="search-cabin-image"
                        >

                    </div>

                    <div class="search-cabin-content">

                        <h3>
                            <?= htmlspecialchars(
                                $cabin['name']
                            ) ?>
                        </h3>

                        <p class="search-cabin-location">
                            <?= htmlspecialchars(
                                $cabin['location']
                            ) ?>
                        </p>

                        <p class="search-cabin-price">

                            ₪<?= number_format(
                                (float) $cabin['price_per_night'],
                                2
                            ) ?>

                        </p>

                        <a
                            href="booking.php?cabin_id=<?= (int) $cabin['id'] ?>"
                            class="search-view-button"
                        >
                            <?= htmlspecialchars(t('view')) ?>
                        </a>

                    </div>

                </article>

            <?php endwhile; ?>

        </div>

    </div>

</main>

<?php include "footer.php"; ?>

</body>
</html>

<?php

$stmt->close();

if ($alt_stmt) {
    $alt_stmt->close();
}

$conn->close();

?>