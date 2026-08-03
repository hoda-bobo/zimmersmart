<?php
function addLead($conn, $user_id, $cabin_id, $lead_type, $notes){
    $stmt = $conn->prepare("
        INSERT INTO leads (user_id, cabin_id, lead_type, notes)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->bind_param("iiss", $user_id, $cabin_id, $lead_type, $notes);
    $stmt->execute();
}
?>