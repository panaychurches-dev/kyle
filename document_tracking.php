<?php

function ensureDocumentQrCodeColumn(mysqli $conn): bool
{
    $columns = $conn->query("SHOW COLUMNS FROM documents LIKE 'qr_code'");
    if (!$columns) {
        error_log('Could not inspect document QR code column: ' . $conn->error);
        return false;
    }

    if ($columns->num_rows === 0) {
        $legacyColumn = $conn->query("SHOW COLUMNS FROM documents LIKE 'tracking_number'");
        if (!$legacyColumn) {
            error_log('Could not inspect legacy document tracking column: ' . $conn->error);
            return false;
        }

        if ($legacyColumn->num_rows === 0) {
            error_log('Documents table has neither qr_code nor legacy tracking_number column.');
            return false;
        }

        if (!$conn->query('ALTER TABLE documents CHANGE COLUMN tracking_number qr_code varchar(64) NOT NULL')) {
            error_log('Could not migrate documents.tracking_number to qr_code: ' . $conn->error);
            return false;
        }
    }

    $indexes = $conn->query("SHOW INDEX FROM documents WHERE Key_name = 'qr_code'");
    if (!$indexes) {
        error_log('Could not inspect document QR code index: ' . $conn->error);
        return false;
    }

    if ($indexes->num_rows === 0) {
        $legacyIndex = $conn->query("SHOW INDEX FROM documents WHERE Key_name = 'tracking_number'");
        if (!$legacyIndex) {
            error_log('Could not inspect legacy document tracking index: ' . $conn->error);
            return false;
        }

        if ($legacyIndex->num_rows > 0) {
            if (!$conn->query('ALTER TABLE documents DROP INDEX tracking_number, ADD UNIQUE KEY qr_code (qr_code)')) {
                error_log('Could not migrate unique document QR code index: ' . $conn->error);
                return false;
            }
        } elseif (!$conn->query('ALTER TABLE documents ADD UNIQUE KEY qr_code (qr_code)')) {
            error_log('Could not add unique document QR code index: ' . $conn->error);
            return false;
        }
    }

    return true;
}

function ensureDocumentTrackingEventsTable(mysqli $conn): bool
{
    return (bool) $conn->query("
        CREATE TABLE IF NOT EXISTS document_tracking_events (
            id int(11) NOT NULL AUTO_INCREMENT,
            document_id int(11) NOT NULL,
            event_type varchar(20) NOT NULL,
            office varchar(150) NOT NULL,
            received_by_user_id int(11) DEFAULT NULL,
            received_by_name varchar(150) NOT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY document_event (document_id, id),
            KEY receiver_user (received_by_user_id)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_general_ci
    ");
}

function recordDocumentTrackingEvent(
    mysqli $conn,
    int $documentId,
    string $eventType,
    string $office,
    ?int $receivedByUserId,
    string $receivedByName
): bool {
    $stmt = $conn->prepare(
        'INSERT INTO document_tracking_events
            (document_id, event_type, office, received_by_user_id, received_by_name)
         VALUES (?, ?, ?, ?, ?)'
    );

    if (!$stmt) {
        error_log('Could not prepare document tracking event: ' . $conn->error);
        return false;
    }

    $stmt->bind_param(
        'issis',
        $documentId,
        $eventType,
        $office,
        $receivedByUserId,
        $receivedByName
    );

    $success = $stmt->execute();
    if (!$success) {
        error_log('Could not save document tracking event: ' . $stmt->error);
    }
    $stmt->close();

    return $success;
}
