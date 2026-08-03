<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/connection.php";
require_once __DIR__ . "/language.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (($_SESSION['user_type'] ?? '') !== 'customer') {
    die(htmlspecialchars(t('review_customers_only')));
}

$user_id = (int) $_SESSION['user_id'];

if (!isset($_GET['booking_id']) || !ctype_digit((string) $_GET['booking_id'])) {
    die(htmlspecialchars(t('review_missing_booking_id')));
}

$booking_id = (int) $_GET['booking_id'];

$stmt = $conn->prepare("
    SELECT 
        b.id AS booking_id,
        b.user_id,
        b.cabin_id,
        b.end_date,
        b.status,
        b.review_submitted,
        c.name AS cabin_name
    FROM bookings b
    INNER JOIN cabins c 
        ON b.cabin_id = c.id
    WHERE b.id = ?
      AND b.user_id = ?
    LIMIT 1
");

if (!$stmt) {
    die(htmlspecialchars(t('review_database_error')));
}

$stmt->bind_param("ii", $booking_id, $user_id);
$stmt->execute();

$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    die(htmlspecialchars(t('review_booking_not_found')));
}

if ($booking['status'] === 'cancelled') {
    die(htmlspecialchars(t('review_cancelled_booking')));
}

if ($booking['end_date'] >= date("Y-m-d")) {
    die(htmlspecialchars(t('review_after_stay_only')));
}

if ((int) $booking['review_submitted'] === 1) {
    die(htmlspecialchars(t('review_already_submitted')));
}

$message = "";
$message_type = "";

if (isset($_POST['submit_review'])) {

    $rating = isset($_POST['rating'])
        ? (int) $_POST['rating']
        : 0;

    $comment = trim($_POST['comment'] ?? '');

    if ($rating < 1 || $rating > 5) {

        $message = t('review_invalid_rating');
        $message_type = "error";

    } elseif ($comment === '') {

        $message = t('review_comment_required');
        $message_type = "error";

    } elseif (mb_strlen($comment) < 5) {

        $message = t('review_comment_too_short');
        $message_type = "error";

    } elseif (mb_strlen($comment) > 1000) {

        $message = t('review_comment_too_long');
        $message_type = "error";

    } else {

        $conn->begin_transaction();

        try {

            $insert = $conn->prepare("
                INSERT INTO reviews
                    (user_id, cabin_id, rating, comment, review_date)
                VALUES
                    (?, ?, ?, ?, NOW())
            ");

            if (!$insert) {
                throw new Exception("Review insert preparation failed");
            }

            $insert->bind_param(
                "iiis",
                $user_id,
                $booking['cabin_id'],
                $rating,
                $comment
            );

            if (!$insert->execute()) {
                throw new Exception("Review insert failed");
            }

            $insert->close();

            $update = $conn->prepare("
                UPDATE bookings
                SET review_submitted = 1
                WHERE id = ?
                  AND user_id = ?
            ");

            if (!$update) {
                throw new Exception("Booking update preparation failed");
            }

            $update->bind_param(
                "ii",
                $booking_id,
                $user_id
            );

            if (!$update->execute()) {
                throw new Exception("Booking update failed");
            }

            $update->close();

            $points = $conn->prepare("
                UPDATE users
                SET loyalty_points = loyalty_points + 20
                WHERE id = ?
            ");

            if (!$points) {
                throw new Exception("Points update preparation failed");
            }

            $points->bind_param("i", $user_id);

            if (!$points->execute()) {
                throw new Exception("Points update failed");
            }

            $points->close();

            $conn->commit();

            header("Location: mybookings.php?review=success");
            exit();

        } catch (Throwable $exception) {

            $conn->rollback();

            $message = t('review_submit_error');
            $message_type = "error";
        }
    }
}

$selected_rating = isset($_POST['rating'])
    ? (int) $_POST['rating']
    : 0;

$comment_value = $_POST['comment'] ?? '';

?>
<!DOCTYPE html>

<html
    lang="<?= htmlspecialchars(current_language()) ?>"
    dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>"
>

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars(t('review_page_title')) ?>
    </title>

    <link
        rel="stylesheet"
        href="style.css?v=<?= time() ?>"
    >

</head>

<body>

<?php include __DIR__ . "/navbar.php"; ?>

<main class="review-page">

    <div class="review-container">

        <section class="review-hero">

            <div class="review-hero-content">

                <span class="review-eyebrow">
                    <?= htmlspecialchars(t('review_mini_title')) ?>
                </span>

                <h1>
                    <?= htmlspecialchars(t('review_heading')) ?>
                </h1>

                <p>
                    <?= htmlspecialchars(t('review_description')) ?>

                    <strong>
                        <?= htmlspecialchars($booking['cabin_name']) ?>
                    </strong>
                </p>

            </div>

            <div
                class="review-hero-decoration"
                aria-hidden="true"
            >
                <span>★</span>
            </div>

        </section>

        <section class="review-layout">

            <aside class="review-summary-card">

                <div class="review-summary-icon">
                    🏡
                </div>

                <span class="review-summary-label">
                    <?= htmlspecialchars(t('review_cabin_label')) ?>
                </span>

                <h2>
                    <?= htmlspecialchars($booking['cabin_name']) ?>
                </h2>

                <div class="review-summary-divider"></div>

                <div class="review-reward-box">

                    <span class="review-reward-icon">
                        🎁
                    </span>

                    <div>
                        <strong>
                            <?= htmlspecialchars(t('review_reward_title')) ?>
                        </strong>

                        <p>
                            <?= htmlspecialchars(t('review_reward_text')) ?>
                        </p>
                    </div>

                </div>

                <p class="review-summary-note">
                    <?= htmlspecialchars(t('review_public_note')) ?>
                </p>

            </aside>

            <section class="review-form-card">

                <div class="review-form-heading">

                    <span>
                        <?= htmlspecialchars(t('review_form_step')) ?>
                    </span>

                    <h2>
                        <?= htmlspecialchars(t('review_form_title')) ?>
                    </h2>

                    <p>
                        <?= htmlspecialchars(t('review_form_description')) ?>
                    </p>

                </div>

                <?php if ($message !== ""): ?>

                    <div
                        class="review-message <?= htmlspecialchars($message_type) ?>"
                    >
                        <?= htmlspecialchars($message) ?>
                    </div>

                <?php endif; ?>

                <form
                    method="POST"
                    class="review-form"
                    id="reviewForm"
                >

                    <fieldset class="review-rating-field">

                        <legend>
                            <?= htmlspecialchars(t('review_rating_label')) ?>
                            <span class="required-mark">*</span>
                        </legend>

                        <p class="review-rating-helper">
                            <?= htmlspecialchars(t('review_rating_helper')) ?>
                        </p>

                        <div
                            class="review-stars"
                            role="radiogroup"
                            aria-label="<?= htmlspecialchars(t('review_rating_label')) ?>"
                        >

                            <?php for ($value = 5; $value >= 1; $value--): ?>

                                <input
                                    type="radio"
                                    name="rating"
                                    id="rating_<?= $value ?>"
                                    value="<?= $value ?>"
                                    <?= $selected_rating === $value ? 'checked' : '' ?>
                                    required
                                >

                                <label
                                    for="rating_<?= $value ?>"
                                    title="<?= htmlspecialchars(t('review_rating_' . $value)) ?>"
                                >
                                    ★
                                </label>

                            <?php endfor; ?>

                        </div>

                        <div
                            class="review-rating-text"
                            id="ratingText"
                            data-rating-1="<?= htmlspecialchars(t('review_rating_1')) ?>"
                            data-rating-2="<?= htmlspecialchars(t('review_rating_2')) ?>"
                            data-rating-3="<?= htmlspecialchars(t('review_rating_3')) ?>"
                            data-rating-4="<?= htmlspecialchars(t('review_rating_4')) ?>"
                            data-rating-5="<?= htmlspecialchars(t('review_rating_5')) ?>"
                            data-empty="<?= htmlspecialchars(t('review_no_rating_selected')) ?>"
                        >
                            <?= $selected_rating >= 1 && $selected_rating <= 5
                                ? htmlspecialchars(t('review_rating_' . $selected_rating))
                                : htmlspecialchars(t('review_no_rating_selected')) ?>
                        </div>

                    </fieldset>

                    <div class="review-form-field">

                        <label for="comment">
                            <?= htmlspecialchars(t('review_comment_label')) ?>
                            <span class="required-mark">*</span>
                        </label>

                        <textarea
                            name="comment"
                            id="comment"
                            rows="7"
                            maxlength="1000"
                            required
                            placeholder="<?= htmlspecialchars(t('review_comment_placeholder')) ?>"
                        ><?= htmlspecialchars($comment_value) ?></textarea>

                        <div class="review-character-row">

                            <small>
                                <?= htmlspecialchars(t('review_comment_helper')) ?>
                            </small>

                            <small>
                                <span id="commentCount">
                                    <?= mb_strlen($comment_value) ?>
                                </span>/1000
                            </small>

                        </div>

                    </div>

                    <button
                        class="review-submit-button"
                        type="submit"
                        name="submit_review"
                    >
                        <span>
                            <?= htmlspecialchars(t('review_submit_button')) ?>
                        </span>

                        <small>
                            <?= htmlspecialchars(t('review_submit_reward')) ?>
                        </small>
                    </button>

                    <a
                        href="mybookings.php"
                        class="review-back-link"
                    >
                        <?= htmlspecialchars(t('review_back_to_bookings')) ?>
                    </a>

                </form>

            </section>

        </section>

    </div>

</main>

<?php include __DIR__ . "/footer.php"; ?>

<script>

document.addEventListener("DOMContentLoaded", function () {

    const ratingInputs = document.querySelectorAll(
        '.review-stars input[name="rating"]'
    );

    const ratingText = document.getElementById("ratingText");
    const comment = document.getElementById("comment");
    const commentCount = document.getElementById("commentCount");

    ratingInputs.forEach(function (input) {

        input.addEventListener("change", function () {

            const rating = this.value;
            const translationKey = "rating" + rating;

            if (ratingText && ratingText.dataset[translationKey]) {
                ratingText.textContent =
                    ratingText.dataset[translationKey];
            }

        });

    });

    if (comment && commentCount) {

        comment.addEventListener("input", function () {
            commentCount.textContent = this.value.length;
        });

    }

});

</script>

</body>
</html>