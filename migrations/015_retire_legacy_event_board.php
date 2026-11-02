<?php
/**
 * Migration 015: retire the generic live-event board.
 *
 * The public board is now driven by Group Challenges and Awards. Remove the
 * display-only event ledger and any leftover generic legacy event rows, while
 * retaining event rows owned by brackets and Cadet of the Month.
 */
return static function (PDO $db): void {
    $db->exec("
        DELETE FROM events
        WHERE LOWER(COALESCE(TRIM(event_type),'')) NOT IN ('bracket','cadet_of_month')
          AND NOT EXISTS (
              SELECT 1 FROM bracket_score_events bse WHERE bse.event_id=events.id
          )
          AND NOT EXISTS (
              SELECT 1 FROM cadet_of_month_awards cotm WHERE cotm.event_id=events.id
          )
    ");

    // This table only backed the retired single-event and multi-squadron forms.
    // All rows in it are legacy board entries and are intentionally discarded.
    $db->exec("DROP TABLE IF EXISTS live_event_results");

    // Migration 014 seeded this type solely for the retired generic event form.
    $db->exec("DELETE FROM event_type_config WHERE event_type='award'");
};
