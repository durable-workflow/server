RETURN (
    SELECT JSON_OBJECT(
        'triggers', (
            SELECT COALESCE(JSON_ARRAYAGG(JSON_ARRAY(name, table_name, event_name, timing, orientation,
                action_order, body_hash, owner_matches)), JSON_ARRAY())
            FROM (
                SELECT TRIGGER_NAME AS name, EVENT_OBJECT_TABLE AS table_name, EVENT_MANIPULATION AS event_name,
                    ACTION_TIMING AS timing, ACTION_ORIENTATION AS orientation, CAST(ACTION_ORDER AS SIGNED) AS action_order,
                    SHA2(TRIM(TRAILING ';' FROM TRIM(ACTION_STATEMENT)),256) AS body_hash,
                    CAST(BINARY DEFINER=BINARY CURRENT_USER() AS SIGNED) AS owner_matches
                FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME LIMIT 28
            ) AS checkpoint_triggers
        ),
        'routine', (
            SELECT JSON_ARRAY(ROUTINE_TYPE, SECURITY_TYPE, SQL_DATA_ACCESS, DTD_IDENTIFIER, IS_DETERMINISTIC,
                SHA2(TRIM(TRAILING ';' FROM TRIM(ROUTINE_DEFINITION)),256),
                CAST(BINARY DEFINER=BINARY CURRENT_USER() AS SIGNED))
            FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='dw_child_summary_catalog'
                AND ROUTINE_TYPE='FUNCTION'
        ),
        'view', (
            SELECT JSON_ARRAY(SECURITY_TYPE, CHECK_OPTION, IS_UPDATABLE, SHA2(VIEW_DEFINITION,256),
                CAST(BINARY DEFINER=BINARY CURRENT_USER() AS SIGNED))
            FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dw_child_summary_catalog'
        ),
        'routines', (SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()),
        'events', (SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE())
    )
)
