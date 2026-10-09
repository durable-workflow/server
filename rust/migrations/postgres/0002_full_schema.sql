CREATE TABLE public.activity_attempts (
    id character varying(26) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    activity_execution_id character varying(26) NOT NULL,
    workflow_task_id character varying(26),
    attempt_number integer NOT NULL,
    status character varying(255) NOT NULL,
    lease_owner character varying(255),
    started_at timestamp(6) without time zone NOT NULL,
    last_heartbeat_at timestamp(6) without time zone,
    lease_expires_at timestamp(6) without time zone,
    closed_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone,
    worker_attempt_id character varying(255)
);
CREATE TABLE public.activity_executions (
    id character varying(26) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    sequence integer NOT NULL,
    activity_class character varying(255) NOT NULL,
    activity_type character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    payload_codec character varying(255),
    arguments text,
    result text,
    exception text,
    connection character varying(255),
    queue character varying(255),
    attempt_count integer DEFAULT 1 NOT NULL,
    current_attempt_id character varying(26),
    retry_policy json,
    parallel_group_path json,
    activity_options json,
    schedule_deadline_at timestamp(6) without time zone,
    close_deadline_at timestamp(6) without time zone,
    schedule_to_close_deadline_at timestamp(6) without time zone,
    heartbeat_deadline_at timestamp(6) without time zone,
    started_at timestamp(6) without time zone,
    closed_at timestamp(6) without time zone,
    last_heartbeat_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);
CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;
CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);
CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;
CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);
CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;
CREATE TABLE public.runtime_credentials (
    id character varying(64) NOT NULL,
    name character varying(255),
    subject character varying(255) NOT NULL,
    roles json NOT NULL,
    tenant character varying(128) NOT NULL,
    claims json,
    token_prefix character varying(16) NOT NULL,
    token_hash character(64) NOT NULL,
    expires_at timestamp(0) without time zone,
    revoked_at timestamp(0) without time zone,
    rotated_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);
CREATE TABLE public.runtime_external_payload_backup_hold (
    id smallint NOT NULL,
    owner uuid,
    acquired_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone
);
CREATE TABLE public.runtime_external_payload_cleanup_stats (
    namespace character varying(128) NOT NULL,
    passes_total bigint DEFAULT '0'::bigint NOT NULL,
    deleted_references_total bigint DEFAULT '0'::bigint NOT NULL,
    deleted_backing_objects_total bigint DEFAULT '0'::bigint NOT NULL,
    shared_objects_preserved_total bigint DEFAULT '0'::bigint NOT NULL,
    blocked_outcomes_total bigint DEFAULT '0'::bigint NOT NULL,
    storage_driver_failures_total bigint DEFAULT '0'::bigint NOT NULL,
    last_processed integer DEFAULT 0 NOT NULL,
    last_deleted_references integer DEFAULT 0 NOT NULL,
    last_deleted_backing_objects integer DEFAULT 0 NOT NULL,
    last_shared_objects_preserved integer DEFAULT 0 NOT NULL,
    last_blocked_outcomes integer DEFAULT 0 NOT NULL,
    last_storage_driver_failures integer DEFAULT 0 NOT NULL,
    last_pass_status character varying(16),
    last_completed_at timestamp(0) without time zone,
    last_storage_failure_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);
CREATE TABLE public.runtime_external_payload_object_locks (
    bucket smallint NOT NULL
);
CREATE TABLE public.runtime_external_payloads (
    id character varying(64) NOT NULL,
    namespace character varying(128) NOT NULL,
    storage_uri text NOT NULL,
    storage_uri_sha256 character(64) NOT NULL,
    codec character varying(64) NOT NULL,
    sha256 character(64) NOT NULL,
    size_bytes bigint NOT NULL,
    retained_at timestamp(0) without time zone,
    expires_at timestamp(0) without time zone,
    last_fetched_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    upload_status character varying(16) DEFAULT 'ready'::character varying NOT NULL
);
CREATE TABLE public.runtime_payload_completion_budgets (
    id character(64) NOT NULL,
    namespace character varying(128) NOT NULL,
    context json NOT NULL,
    slots json NOT NULL,
    objects json NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);
CREATE TABLE public.search_attribute_definitions (
    id bigint NOT NULL,
    namespace character varying(128) NOT NULL,
    name character varying(128) NOT NULL,
    type character varying(32) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);
CREATE SEQUENCE public.search_attribute_definitions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.search_attribute_definitions_id_seq OWNED BY public.search_attribute_definitions.id;
CREATE TABLE public.workflow_child_calls (
    id bigint NOT NULL,
    parent_workflow_run_id character varying(26) NOT NULL,
    parent_workflow_instance_id character varying(191) NOT NULL,
    sequence integer NOT NULL,
    child_workflow_type character varying(191) NOT NULL,
    child_workflow_class character varying(255) NOT NULL,
    requested_child_id character varying(191),
    resolved_child_instance_id character varying(191),
    resolved_child_run_id character varying(26),
    parent_close_policy character varying(32) DEFAULT 'abandon'::character varying NOT NULL,
    connection character varying(191),
    queue character varying(191),
    compatibility character varying(191),
    retry_policy json,
    timeout_policy json,
    cancellation_propagation boolean DEFAULT false NOT NULL,
    status character varying(32) DEFAULT 'scheduled'::character varying NOT NULL,
    result_payload_reference character varying(191),
    failure_reference character varying(191),
    closed_reason character varying(64),
    scheduled_at timestamp(6) without time zone NOT NULL,
    started_at timestamp(6) without time zone,
    closed_at timestamp(6) without time zone,
    arguments json,
    metadata json,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE SEQUENCE public.workflow_child_calls_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_child_calls_id_seq OWNED BY public.workflow_child_calls.id;
CREATE TABLE public.workflow_child_projection_repairs (
    workflow_history_event_id character varying(26) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    workflow_task_id character varying(26) NOT NULL,
    history_sequence integer NOT NULL,
    failure_id character varying(26),
    failed_child_counted_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_commands (
    id character varying(26) NOT NULL,
    workflow_instance_id character varying(191),
    workflow_run_id character varying(26),
    requested_workflow_run_id character varying(26),
    resolved_workflow_run_id character varying(26),
    command_type character varying(255) NOT NULL,
    target_scope character varying(255) DEFAULT 'instance'::character varying NOT NULL,
    source character varying(255) DEFAULT 'php'::character varying NOT NULL,
    context json,
    request_id character varying(191),
    status character varying(255) NOT NULL,
    outcome character varying(255),
    workflow_class character varying(255),
    workflow_type character varying(255),
    payload_codec character varying(255),
    payload text,
    rejection_reason character varying(255),
    command_sequence integer,
    message_sequence integer,
    accepted_at timestamp(6) without time zone,
    applied_at timestamp(6) without time zone,
    rejected_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_durable_stream_items (
    id bigint NOT NULL,
    stream_id bigint NOT NULL,
    namespace character varying(128) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    stream_name character varying(191) NOT NULL,
    "offset" bigint NOT NULL,
    idempotency_key character varying(191),
    origin character varying(64) DEFAULT 'workflow_command'::character varying NOT NULL,
    origin_reference character varying(191),
    payload json,
    payload_reference character varying(191),
    payload_codec character varying(64),
    item_type character varying(64),
    content_type character varying(191),
    emitted_at timestamp(6) without time zone NOT NULL,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE SEQUENCE public.workflow_durable_stream_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_durable_stream_items_id_seq OWNED BY public.workflow_durable_stream_items.id;
CREATE TABLE public.workflow_durable_streams (
    id bigint NOT NULL,
    namespace character varying(128) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    stream_name character varying(191) NOT NULL,
    status character varying(16) DEFAULT 'open'::character varying NOT NULL,
    last_offset bigint DEFAULT '-1'::bigint NOT NULL,
    total_items bigint DEFAULT '0'::bigint NOT NULL,
    retention_seconds integer,
    opened_at timestamp(6) without time zone,
    last_appended_at timestamp(6) without time zone,
    closed_at timestamp(6) without time zone,
    error_reason character varying(191),
    pending_items bigint DEFAULT '0'::bigint NOT NULL,
    metadata json,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE SEQUENCE public.workflow_durable_streams_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_durable_streams_id_seq OWNED BY public.workflow_durable_streams.id;
CREATE TABLE public.workflow_failures (
    id character varying(26) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    source_kind character varying(255) NOT NULL,
    source_id character varying(255) NOT NULL,
    propagation_kind character varying(255) NOT NULL,
    failure_category character varying(255),
    non_retryable boolean DEFAULT false NOT NULL,
    handled boolean DEFAULT false NOT NULL,
    exception_class character varying(255) NOT NULL,
    message text NOT NULL,
    file text NOT NULL,
    line integer,
    trace_preview text,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_history_events (
    id character varying(26) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    sequence integer NOT NULL,
    event_type character varying(255) NOT NULL,
    payload json,
    workflow_task_id character varying(26),
    workflow_command_id character varying(26),
    recorded_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone,
    recorded_at_utc timestamp(6) without time zone
);
CREATE TABLE public.workflow_inbound_stream_items (
    id bigint NOT NULL,
    stream_id bigint NOT NULL,
    namespace character varying(128) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    stream_name character varying(128) NOT NULL,
    message_id character varying(191) NOT NULL,
    "position" bigint NOT NULL,
    payload_codec character varying(32) NOT NULL,
    payload_blob text NOT NULL,
    payload_hash character varying(64) NOT NULL,
    delivered_run_id character varying(26),
    workflow_command_id character varying(26),
    delivered_at timestamp(6) without time zone,
    consumed_run_id character varying(26),
    consumed_task_id character varying(26),
    consumed_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone,
    payload_released_at timestamp(6) without time zone
);
CREATE SEQUENCE public.workflow_inbound_stream_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_inbound_stream_items_id_seq OWNED BY public.workflow_inbound_stream_items.id;
CREATE TABLE public.workflow_inbound_streams (
    id bigint NOT NULL,
    namespace character varying(128) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    stream_name character varying(128) NOT NULL,
    last_position bigint DEFAULT '0'::bigint NOT NULL,
    cursor_position bigint DEFAULT '0'::bigint NOT NULL,
    cursor_checkpoint_run_id character varying(26),
    waiting_run_id character varying(26),
    waiting_after_position bigint,
    waiting_since timestamp(6) without time zone,
    duplicate_count bigint DEFAULT '0'::bigint NOT NULL,
    malformed_count bigint DEFAULT '0'::bigint NOT NULL,
    last_input_outcome character varying(64),
    last_input_message_id character varying(191),
    last_input_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone,
    cleanup_blocked_at timestamp(6) without time zone,
    cleanup_blocked_reason character varying(64),
    cleanup_blocked_run_id character varying(26)
);
CREATE SEQUENCE public.workflow_inbound_streams_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_inbound_streams_id_seq OWNED BY public.workflow_inbound_streams.id;
CREATE TABLE public.workflow_instances (
    id character varying(191) NOT NULL,
    workflow_class character varying(255) NOT NULL,
    workflow_type character varying(255) NOT NULL,
    namespace character varying(255),
    business_key character varying(191),
    visibility_labels json,
    memo json,
    execution_timeout_seconds integer,
    current_run_id character varying(26),
    run_count integer DEFAULT 0 NOT NULL,
    last_message_sequence integer DEFAULT 0 NOT NULL,
    reserved_at timestamp(6) without time zone,
    started_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_links (
    id character varying(26) NOT NULL,
    link_type character varying(255) NOT NULL,
    sequence integer,
    parallel_group_path json,
    parent_workflow_instance_id character varying(191) NOT NULL,
    parent_workflow_run_id character varying(26) NOT NULL,
    child_workflow_instance_id character varying(191) NOT NULL,
    child_workflow_run_id character varying(26) NOT NULL,
    is_primary_parent boolean DEFAULT false NOT NULL,
    parent_close_policy character varying(255) DEFAULT 'abandon'::character varying NOT NULL,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_memos (
    id bigint NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    key character varying(191) NOT NULL,
    value json NOT NULL,
    upserted_at_sequence integer NOT NULL,
    inherited_from_parent boolean DEFAULT false NOT NULL,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone,
    portable_value json,
    portable_value_sequence integer
);
CREATE SEQUENCE public.workflow_memos_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_memos_id_seq OWNED BY public.workflow_memos.id;
CREATE TABLE public.workflow_messages (
    id bigint NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    workflow_run_id character varying(26),
    direction character varying(16) NOT NULL,
    channel character varying(64) NOT NULL,
    stream_key character varying(191) NOT NULL,
    sequence bigint NOT NULL,
    source_workflow_instance_id character varying(191),
    source_workflow_run_id character varying(26),
    target_workflow_instance_id character varying(191),
    target_workflow_run_id character varying(26),
    correlation_id character varying(191),
    idempotency_key character varying(191),
    payload_reference character varying(191),
    consume_state character varying(16) DEFAULT 'pending'::character varying NOT NULL,
    consumed_at timestamp(6) without time zone,
    consumed_by_sequence integer,
    expires_at timestamp(6) without time zone,
    delivery_attempt_count integer DEFAULT 0 NOT NULL,
    last_delivery_attempt_at timestamp(6) without time zone,
    last_delivery_error text,
    metadata json,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE SEQUENCE public.workflow_messages_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_messages_id_seq OWNED BY public.workflow_messages.id;
CREATE TABLE public.workflow_namespaces (
    id bigint NOT NULL,
    name character varying(128) NOT NULL,
    description character varying(1000),
    retention_days integer DEFAULT 30,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    external_payload_storage json,
    retention_mode character varying(32) DEFAULT 'bounded'::character varying NOT NULL
);
CREATE SEQUENCE public.workflow_namespaces_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_namespaces_id_seq OWNED BY public.workflow_namespaces.id;
CREATE TABLE public.workflow_run_lineage_entries (
    id character varying(64) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    direction character varying(255) NOT NULL,
    lineage_id character varying(191) NOT NULL,
    "position" integer NOT NULL,
    link_type character varying(255) NOT NULL,
    child_call_id character varying(26),
    sequence integer,
    is_primary_parent boolean DEFAULT false NOT NULL,
    related_workflow_instance_id character varying(191),
    related_workflow_run_id character varying(26),
    related_run_number integer,
    related_workflow_type character varying(191),
    related_workflow_class character varying(255),
    status character varying(255),
    status_bucket character varying(255),
    closed_reason character varying(255),
    linked_at timestamp(6) without time zone,
    payload json,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_run_summaries (
    id character varying(26) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    run_number integer NOT NULL,
    is_current_run boolean DEFAULT false NOT NULL,
    engine_source character varying(255) DEFAULT 'v2'::character varying NOT NULL,
    projection_schema_version smallint,
    class character varying(255) NOT NULL,
    workflow_type character varying(255) NOT NULL,
    namespace character varying(255),
    compatibility character varying(255),
    declared_entry_mode character varying(255),
    declared_contract_source character varying(255),
    business_key character varying(191),
    visibility_labels json,
    status character varying(255) NOT NULL,
    status_bucket character varying(255) NOT NULL,
    closed_reason character varying(255),
    connection character varying(255),
    queue character varying(255),
    started_at timestamp(6) without time zone,
    sort_timestamp timestamp(6) without time zone,
    sort_key character varying(64),
    closed_at timestamp(6) without time zone,
    archived_at timestamp(6) without time zone,
    archive_command_id character varying(26),
    archive_reason character varying(255),
    duration_ms bigint,
    wait_kind character varying(255),
    wait_reason text,
    wait_started_at timestamp(6) without time zone,
    wait_deadline_at timestamp(6) without time zone,
    open_wait_id character varying(191),
    resume_source_kind character varying(255),
    resume_source_id character varying(191),
    next_task_at timestamp(6) without time zone,
    liveness_state character varying(255),
    liveness_reason text,
    repair_blocked_reason character varying(255),
    repair_attention boolean DEFAULT false NOT NULL,
    task_problem boolean DEFAULT false NOT NULL,
    next_task_id character varying(26),
    next_task_type character varying(255),
    next_task_status character varying(255),
    next_task_lease_expires_at timestamp(6) without time zone,
    exception_count integer DEFAULT 0 NOT NULL,
    history_event_count integer DEFAULT 0 NOT NULL,
    history_size_bytes bigint DEFAULT '0'::bigint NOT NULL,
    continue_as_new_recommended boolean DEFAULT false NOT NULL,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone,
    history_fan_out integer DEFAULT 0 NOT NULL,
    history_budget_pressure character varying(32) DEFAULT 'ok'::character varying NOT NULL
);
CREATE TABLE public.workflow_run_timeline_entries (
    id character varying(64) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    history_event_id character varying(26) NOT NULL,
    sequence integer NOT NULL,
    type character varying(255) NOT NULL,
    kind character varying(255) NOT NULL,
    entry_kind character varying(255) DEFAULT 'point'::character varying NOT NULL,
    source_kind character varying(255),
    source_id character varying(191),
    summary text,
    recorded_at timestamp(6) without time zone,
    command_id character varying(26),
    command_sequence integer,
    task_id character varying(26),
    activity_execution_id character varying(26),
    timer_id character varying(191),
    failure_id character varying(26),
    payload json,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_run_timer_entries (
    id character varying(64) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    timer_id character varying(191) NOT NULL,
    schema_version smallint DEFAULT '1'::smallint NOT NULL,
    "position" integer NOT NULL,
    sequence integer,
    status character varying(255) NOT NULL,
    source_status character varying(255),
    delay_seconds integer,
    fire_at timestamp(6) without time zone,
    fired_at timestamp(6) without time zone,
    cancelled_at timestamp(6) without time zone,
    timer_kind character varying(191),
    condition_wait_id character varying(191),
    condition_key character varying(191),
    condition_definition_fingerprint character varying(191),
    history_authority character varying(255),
    history_unsupported_reason character varying(255),
    payload json,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_run_timers (
    id character varying(26) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    sequence integer NOT NULL,
    status character varying(255) NOT NULL,
    delay_seconds bigint NOT NULL,
    fire_at timestamp(6) without time zone NOT NULL,
    fired_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_run_waits (
    id character varying(64) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    wait_id character varying(191) NOT NULL,
    "position" integer NOT NULL,
    kind character varying(255) NOT NULL,
    sequence integer,
    status character varying(255) NOT NULL,
    source_status character varying(255),
    summary text,
    opened_at timestamp(6) without time zone,
    deadline_at timestamp(6) without time zone,
    resolved_at timestamp(6) without time zone,
    target_name character varying(191),
    target_type character varying(191),
    task_backed boolean DEFAULT false NOT NULL,
    external_only boolean DEFAULT false NOT NULL,
    resume_source_kind character varying(191),
    resume_source_id character varying(191),
    task_id character varying(26),
    task_type character varying(255),
    task_status character varying(255),
    command_id character varying(26),
    command_sequence integer,
    command_status character varying(255),
    command_outcome character varying(255),
    history_authority character varying(255),
    history_unsupported_reason character varying(255),
    payload json,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_runs (
    id character varying(26) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    run_number integer NOT NULL,
    workflow_class character varying(255) NOT NULL,
    workflow_type character varying(255) NOT NULL,
    namespace character varying(255),
    business_key character varying(191),
    visibility_labels json,
    status character varying(255) NOT NULL,
    closed_reason character varying(255),
    compatibility character varying(255),
    payload_codec character varying(255),
    arguments text,
    output text,
    output_payload_codec character varying(255),
    connection character varying(255),
    queue character varying(255),
    sticky_worker_id character varying(255),
    sticky_until timestamp(6) without time zone,
    last_history_sequence integer DEFAULT 0 NOT NULL,
    last_command_sequence integer DEFAULT 0 NOT NULL,
    message_cursor_position integer DEFAULT 0 NOT NULL,
    run_timeout_seconds integer,
    execution_deadline_at timestamp(6) without time zone,
    run_deadline_at timestamp(6) without time zone,
    started_at timestamp(6) without time zone,
    closed_at timestamp(6) without time zone,
    archived_at timestamp(6) without time zone,
    archive_command_id character varying(26),
    archive_reason character varying(255),
    last_progress_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone,
    import_source character varying(64),
    import_id character varying(64),
    import_dedupe_key character varying(191),
    import_contract_version smallint,
    imported_at timestamp(6) without time zone,
    priority smallint DEFAULT '5'::smallint NOT NULL,
    fairness_key character varying(64),
    fairness_weight smallint DEFAULT '1'::smallint NOT NULL,
    cancellation_request_command_id character varying(26),
    cancellation_requested_at timestamp(6) without time zone,
    cancellation_deadline_at timestamp(6) without time zone,
    cancellation_delivery_sequence integer,
    cancellation_delivered_at timestamp(6) without time zone,
    details_pruned_at timestamp(6) without time zone,
    cancellation_scope_recovery_until timestamp(6) without time zone
);
CREATE TABLE public.workflow_schedule_history_events (
    id character varying(26) NOT NULL,
    workflow_schedule_id character varying(26) NOT NULL,
    schedule_id character varying(255) NOT NULL,
    namespace character varying(255),
    sequence integer NOT NULL,
    event_type character varying(255) NOT NULL,
    payload json,
    workflow_instance_id character varying(191),
    workflow_run_id character varying(26),
    recorded_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone,
    occurrence_at_utc character varying(26)
);
CREATE TABLE public.workflow_schedules (
    id character varying(26) NOT NULL,
    schedule_id character varying(255) NOT NULL,
    namespace character varying(255),
    spec json,
    action json,
    status character varying(16) DEFAULT 'active'::character varying NOT NULL,
    overlap_policy character varying(32) DEFAULT 'skip'::character varying NOT NULL,
    note text,
    memo json,
    search_attributes json,
    visibility_labels json,
    jitter_seconds integer DEFAULT 0 NOT NULL,
    max_runs bigint,
    remaining_actions bigint,
    fires_count bigint DEFAULT '0'::bigint NOT NULL,
    failures_count bigint DEFAULT '0'::bigint NOT NULL,
    recent_actions json,
    buffered_actions json,
    last_fired_at timestamp(6) without time zone,
    next_fire_at timestamp(6) without time zone,
    latest_workflow_instance_id character varying(191),
    connection character varying(255),
    queue character varying(255),
    paused_at timestamp(6) without time zone,
    deleted_at timestamp(6) without time zone,
    last_skip_reason character varying(64),
    last_skipped_at timestamp(6) without time zone,
    skipped_trigger_count bigint DEFAULT '0'::bigint NOT NULL,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_search_attributes (
    id bigint NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    key character varying(191) NOT NULL,
    type character varying(16) NOT NULL,
    value_string text,
    value_keyword character varying(255),
    value_int bigint,
    value_float double precision,
    value_bool boolean,
    value_datetime timestamp(6) without time zone,
    upserted_at_sequence integer NOT NULL,
    inherited_from_parent boolean DEFAULT false NOT NULL,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone,
    value_keyword_list json
);
CREATE SEQUENCE public.workflow_search_attributes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_search_attributes_id_seq OWNED BY public.workflow_search_attributes.id;
CREATE TABLE public.workflow_service_calls (
    id character varying(26) NOT NULL,
    workflow_service_endpoint_id character varying(26),
    workflow_service_id character varying(26),
    workflow_service_operation_id character varying(26),
    namespace character varying(255),
    endpoint_name character varying(191) NOT NULL,
    service_name character varying(191) NOT NULL,
    operation_name character varying(191) NOT NULL,
    caller_namespace character varying(255),
    caller_workflow_instance_id character varying(191),
    caller_workflow_run_id character varying(26),
    target_namespace character varying(255),
    linked_workflow_instance_id character varying(191),
    linked_workflow_run_id character varying(26),
    linked_workflow_update_id character varying(26),
    status character varying(32) NOT NULL,
    outcome character varying(64),
    operation_mode character varying(32) NOT NULL,
    resolved_binding_kind character varying(64),
    resolved_target_reference character varying(191),
    payload_codec character varying(255),
    input_payload_reference character varying(191),
    output_payload_reference character varying(191),
    failure_payload_reference character varying(191),
    failure_message text,
    idempotency_key character varying(191),
    deadline_policy json,
    idempotency_policy json,
    cancellation_policy json,
    retry_policy json,
    boundary_policy json,
    metadata json,
    outcome_category character varying(32),
    outcome_reason character varying(191),
    outcome_message text,
    outcome_metadata json,
    policy_name character varying(191),
    retry_after_seconds integer,
    caller_principal_subject character varying(191),
    caller_principal_method character varying(64),
    caller_principal_roles json,
    caller_principal_tenant character varying(191),
    caller_principal_claims json,
    accepted_at timestamp(6) without time zone,
    started_at timestamp(6) without time zone,
    completed_at timestamp(6) without time zone,
    failed_at timestamp(6) without time zone,
    cancelled_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_service_endpoints (
    id character varying(26) NOT NULL,
    namespace character varying(255),
    endpoint_name character varying(191) NOT NULL,
    description text,
    boundary_policy json,
    metadata json,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_service_operations (
    id character varying(26) NOT NULL,
    workflow_service_endpoint_id character varying(26) NOT NULL,
    workflow_service_id character varying(26) NOT NULL,
    namespace character varying(255),
    operation_name character varying(191) NOT NULL,
    description text,
    operation_mode character varying(32) NOT NULL,
    handler_binding_kind character varying(64) NOT NULL,
    handler_target_reference character varying(191),
    handler_binding json,
    deadline_policy json,
    idempotency_policy json,
    cancellation_policy json,
    retry_policy json,
    boundary_policy json,
    metadata json,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_services (
    id character varying(26) NOT NULL,
    workflow_service_endpoint_id character varying(26) NOT NULL,
    namespace character varying(255),
    service_name character varying(191) NOT NULL,
    description text,
    boundary_policy json,
    metadata json,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_signal_records (
    id character varying(26) NOT NULL,
    workflow_command_id character varying(26) NOT NULL,
    workflow_instance_id character varying(191),
    workflow_run_id character varying(26),
    target_scope character varying(255) DEFAULT 'instance'::character varying NOT NULL,
    requested_workflow_run_id character varying(26),
    resolved_workflow_run_id character varying(26),
    signal_name character varying(255) NOT NULL,
    signal_wait_id character varying(255),
    status character varying(255) NOT NULL,
    outcome character varying(255),
    command_sequence integer,
    workflow_sequence integer,
    payload_codec character varying(255),
    arguments text,
    validation_errors json,
    rejection_reason character varying(255),
    received_at timestamp(6) without time zone,
    applied_at timestamp(6) without time zone,
    rejected_at timestamp(6) without time zone,
    closed_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_task_poll_cursors (
    namespace character varying(128) NOT NULL,
    task_queue character varying(255) NOT NULL,
    next_task_kind character varying(32) NOT NULL,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_tasks (
    id character varying(26) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    namespace character varying(255),
    task_type character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    compatibility character varying(255),
    payload json,
    connection character varying(255),
    queue character varying(255),
    sticky_worker_id character varying(255),
    sticky_until timestamp(6) without time zone,
    sticky_replay_mode character varying(255),
    sticky_claimed_at timestamp(6) without time zone,
    available_at timestamp(6) without time zone,
    leased_at timestamp(6) without time zone,
    lease_owner character varying(255),
    lease_expires_at timestamp(6) without time zone,
    attempt_count integer DEFAULT 0 NOT NULL,
    last_dispatch_attempt_at timestamp(6) without time zone,
    last_dispatched_at timestamp(6) without time zone,
    last_dispatch_error text,
    last_claim_failed_at timestamp(6) without time zone,
    last_claim_error text,
    repair_count integer DEFAULT 0 NOT NULL,
    repair_available_at timestamp(6) without time zone,
    last_error text,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone,
    priority smallint DEFAULT '5'::smallint NOT NULL,
    fairness_key character varying(64),
    fairness_weight smallint DEFAULT '1'::smallint NOT NULL
);
CREATE TABLE public.workflow_update_validation_tasks (
    id character varying(26) NOT NULL,
    idempotency_key character varying(64) NOT NULL,
    namespace character varying(255) NOT NULL,
    workflow_instance_id character varying(191) NOT NULL,
    workflow_run_id character varying(26) NOT NULL,
    workflow_type character varying(255) NOT NULL,
    task_queue character varying(255) NOT NULL,
    compatibility character varying(255),
    workflow_definition_fingerprint character varying(255),
    update_name character varying(255) NOT NULL,
    request_id character varying(255) NOT NULL,
    input_hash character varying(64) NOT NULL,
    payload_codec character varying(255) NOT NULL,
    arguments text NOT NULL,
    command_context json,
    status character varying(255) NOT NULL,
    attempt_count integer DEFAULT 0 NOT NULL,
    lease_owner character varying(255),
    lease_expires_at timestamp(6) without time zone,
    rejection_reason character varying(255),
    rejection_message text,
    failure_type character varying(255),
    validation_errors json,
    approved_at timestamp(6) without time zone,
    rejected_at timestamp(6) without time zone,
    failed_at timestamp(6) without time zone,
    timed_out_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_updates (
    id character varying(26) NOT NULL,
    workflow_command_id character varying(26) NOT NULL,
    workflow_instance_id character varying(191),
    workflow_run_id character varying(26),
    target_scope character varying(255) DEFAULT 'instance'::character varying NOT NULL,
    requested_workflow_run_id character varying(26),
    resolved_workflow_run_id character varying(26),
    update_name character varying(255) NOT NULL,
    status character varying(255) NOT NULL,
    outcome character varying(255),
    command_sequence integer,
    workflow_sequence integer,
    payload_codec character varying(255),
    arguments text,
    result text,
    validation_errors json,
    rejection_reason character varying(255),
    failure_id character varying(26),
    failure_message text,
    accepted_at timestamp(6) without time zone,
    applied_at timestamp(6) without time zone,
    rejected_at timestamp(6) without time zone,
    closed_at timestamp(6) without time zone,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE TABLE public.workflow_worker_build_id_rollouts (
    id bigint NOT NULL,
    namespace character varying(128) NOT NULL,
    task_queue character varying(255) NOT NULL,
    build_id character varying(255) DEFAULT ''::character varying NOT NULL,
    drain_intent character varying(32) DEFAULT 'active'::character varying NOT NULL,
    drained_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    promoted_at timestamp(0) without time zone,
    rolled_back_at timestamp(0) without time zone,
    required_compatibility character varying(255),
    recorded_fingerprint character varying(255),
    compatibility_policy character varying(32),
    workflow_types json
);
CREATE SEQUENCE public.workflow_worker_build_id_rollouts_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_worker_build_id_rollouts_id_seq OWNED BY public.workflow_worker_build_id_rollouts.id;
CREATE TABLE public.workflow_worker_compatibility_heartbeats (
    id bigint NOT NULL,
    worker_id character varying(255) NOT NULL,
    scope_key character varying(64) NOT NULL,
    namespace character varying(255),
    host character varying(255),
    process_id character varying(255),
    connection character varying(255),
    queue character varying(255),
    supported json NOT NULL,
    recorded_at timestamp(6) without time zone NOT NULL,
    expires_at timestamp(6) without time zone NOT NULL,
    created_at timestamp(6) without time zone,
    updated_at timestamp(6) without time zone
);
CREATE SEQUENCE public.workflow_worker_compatibility_heartbeats_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_worker_compatibility_heartbeats_id_seq OWNED BY public.workflow_worker_compatibility_heartbeats.id;
CREATE TABLE public.workflow_worker_registrations (
    id bigint NOT NULL,
    worker_id character varying(255) NOT NULL,
    namespace character varying(128) NOT NULL,
    task_queue character varying(255) NOT NULL,
    runtime character varying(32) NOT NULL,
    sdk_version character varying(64),
    build_id character varying(255),
    supported_workflow_types json,
    workflow_definition_fingerprints json,
    supported_activity_types json,
    max_concurrent_workflow_tasks integer DEFAULT 100 NOT NULL,
    max_concurrent_activity_tasks integer DEFAULT 100 NOT NULL,
    last_heartbeat_at timestamp(0) without time zone,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    capabilities json,
    max_concurrent_worker_sessions integer DEFAULT 10 NOT NULL,
    available_workflow_slots integer,
    available_activity_slots integer,
    available_session_slots integer,
    process_metrics json,
    heartbeat_interval_seconds integer,
    workflow_command_contracts json,
    capability_manifest json
);
CREATE SEQUENCE public.workflow_worker_registrations_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_worker_registrations_id_seq OWNED BY public.workflow_worker_registrations.id;
CREATE TABLE public.workflow_worker_sessions (
    id bigint NOT NULL,
    namespace character varying(128) NOT NULL,
    session_id character varying(255) NOT NULL,
    connection character varying(255),
    queue character varying(255),
    requirements json,
    status character varying(32) DEFAULT 'active'::character varying NOT NULL,
    lease_owner character varying(255),
    lease_expires_at timestamp(0) without time zone,
    ttl_expires_at timestamp(0) without time zone,
    closed_at timestamp(0) without time zone,
    failure_reason character varying(128),
    lease_seconds integer DEFAULT 120 NOT NULL,
    ttl_seconds integer DEFAULT 1800 NOT NULL,
    max_concurrent_activities integer DEFAULT 1 NOT NULL,
    create_if_missing boolean DEFAULT true NOT NULL,
    allow_reacquire_after_failure boolean DEFAULT true NOT NULL,
    last_heartbeat_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);
CREATE SEQUENCE public.workflow_worker_sessions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;
ALTER SEQUENCE public.workflow_worker_sessions_id_seq OWNED BY public.workflow_worker_sessions.id;
ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);
ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);
ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);
ALTER TABLE ONLY public.search_attribute_definitions ALTER COLUMN id SET DEFAULT nextval('public.search_attribute_definitions_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_child_calls ALTER COLUMN id SET DEFAULT nextval('public.workflow_child_calls_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_durable_stream_items ALTER COLUMN id SET DEFAULT nextval('public.workflow_durable_stream_items_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_durable_streams ALTER COLUMN id SET DEFAULT nextval('public.workflow_durable_streams_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_inbound_stream_items ALTER COLUMN id SET DEFAULT nextval('public.workflow_inbound_stream_items_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_inbound_streams ALTER COLUMN id SET DEFAULT nextval('public.workflow_inbound_streams_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_memos ALTER COLUMN id SET DEFAULT nextval('public.workflow_memos_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_messages ALTER COLUMN id SET DEFAULT nextval('public.workflow_messages_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_namespaces ALTER COLUMN id SET DEFAULT nextval('public.workflow_namespaces_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_search_attributes ALTER COLUMN id SET DEFAULT nextval('public.workflow_search_attributes_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_worker_build_id_rollouts ALTER COLUMN id SET DEFAULT nextval('public.workflow_worker_build_id_rollouts_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_worker_compatibility_heartbeats ALTER COLUMN id SET DEFAULT nextval('public.workflow_worker_compatibility_heartbeats_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_worker_registrations ALTER COLUMN id SET DEFAULT nextval('public.workflow_worker_registrations_id_seq'::regclass);
ALTER TABLE ONLY public.workflow_worker_sessions ALTER COLUMN id SET DEFAULT nextval('public.workflow_worker_sessions_id_seq'::regclass);
ALTER TABLE ONLY public.activity_attempts
    ADD CONSTRAINT activity_attempts_activity_execution_id_attempt_number_unique UNIQUE (activity_execution_id, attempt_number);
ALTER TABLE ONLY public.activity_attempts
    ADD CONSTRAINT activity_attempts_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.activity_executions
    ADD CONSTRAINT activity_executions_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.activity_executions
    ADD CONSTRAINT activity_executions_workflow_run_id_sequence_unique UNIQUE (workflow_run_id, sequence);
ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);
ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.runtime_credentials
    ADD CONSTRAINT runtime_credentials_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.runtime_credentials
    ADD CONSTRAINT runtime_credentials_token_hash_unique UNIQUE (token_hash);
ALTER TABLE ONLY public.runtime_external_payload_backup_hold
    ADD CONSTRAINT runtime_external_payload_backup_hold_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.runtime_external_payload_cleanup_stats
    ADD CONSTRAINT runtime_external_payload_cleanup_stats_pkey PRIMARY KEY (namespace);
ALTER TABLE ONLY public.runtime_external_payload_object_locks
    ADD CONSTRAINT runtime_external_payload_object_locks_pkey PRIMARY KEY (bucket);
ALTER TABLE ONLY public.runtime_external_payloads
    ADD CONSTRAINT runtime_external_payloads_namespace_uri_unique UNIQUE (namespace, storage_uri_sha256);
ALTER TABLE ONLY public.runtime_external_payloads
    ADD CONSTRAINT runtime_external_payloads_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.runtime_payload_completion_budgets
    ADD CONSTRAINT runtime_payload_completion_budgets_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.search_attribute_definitions
    ADD CONSTRAINT search_attribute_definitions_namespace_name_unique UNIQUE (namespace, name);
ALTER TABLE ONLY public.search_attribute_definitions
    ADD CONSTRAINT search_attribute_definitions_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_durable_stream_items
    ADD CONSTRAINT wf_durable_stream_items_idempotency_unique UNIQUE (stream_id, idempotency_key);
ALTER TABLE ONLY public.workflow_durable_stream_items
    ADD CONSTRAINT wf_durable_stream_items_stream_offset_unique UNIQUE (stream_id, "offset");
ALTER TABLE ONLY public.workflow_durable_streams
    ADD CONSTRAINT wf_durable_streams_run_name_unique UNIQUE (workflow_run_id, stream_name);
ALTER TABLE ONLY public.workflow_inbound_stream_items
    ADD CONSTRAINT wf_inbound_items_stream_message_unique UNIQUE (stream_id, message_id);
ALTER TABLE ONLY public.workflow_inbound_stream_items
    ADD CONSTRAINT wf_inbound_items_stream_position_unique UNIQUE (stream_id, "position");
ALTER TABLE ONLY public.workflow_inbound_streams
    ADD CONSTRAINT wf_inbound_streams_ns_instance_name_unique UNIQUE (namespace, workflow_instance_id, stream_name);
ALTER TABLE ONLY public.workflow_messages
    ADD CONSTRAINT wf_msgs_stream_seq_unique UNIQUE (workflow_instance_id, stream_key, sequence);
ALTER TABLE ONLY public.workflow_schedule_history_events
    ADD CONSTRAINT wf_schedule_history_schedule_sequence_unique UNIQUE (workflow_schedule_id, sequence);
ALTER TABLE ONLY public.workflow_service_endpoints
    ADD CONSTRAINT wf_service_endpoints_namespace_name_unique UNIQUE (namespace, endpoint_name);
ALTER TABLE ONLY public.workflow_service_operations
    ADD CONSTRAINT wf_service_ops_namespace_service_name_unique UNIQUE (namespace, workflow_service_id, operation_name);
ALTER TABLE ONLY public.workflow_services
    ADD CONSTRAINT wf_services_namespace_endpoint_name_unique UNIQUE (namespace, workflow_service_endpoint_id, service_name);
ALTER TABLE ONLY public.workflow_worker_build_id_rollouts
    ADD CONSTRAINT workflow_build_id_rollouts_scope_unique UNIQUE (namespace, task_queue, build_id);
ALTER TABLE ONLY public.workflow_child_calls
    ADD CONSTRAINT workflow_child_calls_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_child_projection_repairs
    ADD CONSTRAINT workflow_child_projection_repairs_pkey PRIMARY KEY (workflow_history_event_id);
ALTER TABLE ONLY public.workflow_commands
    ADD CONSTRAINT workflow_commands_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_commands
    ADD CONSTRAINT workflow_commands_request_lookup UNIQUE (workflow_instance_id, command_type, request_id);
ALTER TABLE ONLY public.workflow_durable_stream_items
    ADD CONSTRAINT workflow_durable_stream_items_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_durable_streams
    ADD CONSTRAINT workflow_durable_streams_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_failures
    ADD CONSTRAINT workflow_failures_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_history_events
    ADD CONSTRAINT workflow_history_events_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_history_events
    ADD CONSTRAINT workflow_history_events_workflow_run_id_sequence_unique UNIQUE (workflow_run_id, sequence);
ALTER TABLE ONLY public.workflow_inbound_stream_items
    ADD CONSTRAINT workflow_inbound_stream_items_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_inbound_streams
    ADD CONSTRAINT workflow_inbound_streams_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_instances
    ADD CONSTRAINT workflow_instances_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_links
    ADD CONSTRAINT workflow_links_parent_child_type_unique UNIQUE (parent_workflow_run_id, child_workflow_run_id, link_type);
ALTER TABLE ONLY public.workflow_links
    ADD CONSTRAINT workflow_links_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_memos
    ADD CONSTRAINT workflow_memos_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_memos
    ADD CONSTRAINT workflow_memos_run_key_unique UNIQUE (workflow_run_id, key);
ALTER TABLE ONLY public.workflow_messages
    ADD CONSTRAINT workflow_messages_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_namespaces
    ADD CONSTRAINT workflow_namespaces_name_unique UNIQUE (name);
ALTER TABLE ONLY public.workflow_namespaces
    ADD CONSTRAINT workflow_namespaces_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_run_lineage_entries
    ADD CONSTRAINT workflow_run_lineage_entries_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_run_lineage_entries
    ADD CONSTRAINT workflow_run_lineage_run_direction_lineage_unique UNIQUE (workflow_run_id, direction, lineage_id);
ALTER TABLE ONLY public.workflow_run_summaries
    ADD CONSTRAINT workflow_run_summaries_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_run_timeline_entries
    ADD CONSTRAINT workflow_run_timeline_entries_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_run_timeline_entries
    ADD CONSTRAINT workflow_run_timeline_run_event_unique UNIQUE (workflow_run_id, history_event_id);
ALTER TABLE ONLY public.workflow_run_timer_entries
    ADD CONSTRAINT workflow_run_timer_entries_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_run_timer_entries
    ADD CONSTRAINT workflow_run_timer_entries_run_timer_unique UNIQUE (workflow_run_id, timer_id);
ALTER TABLE ONLY public.workflow_run_timers
    ADD CONSTRAINT workflow_run_timers_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_run_timers
    ADD CONSTRAINT workflow_run_timers_workflow_run_id_sequence_unique UNIQUE (workflow_run_id, sequence);
ALTER TABLE ONLY public.workflow_run_waits
    ADD CONSTRAINT workflow_run_waits_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_run_waits
    ADD CONSTRAINT workflow_run_waits_run_wait_unique UNIQUE (workflow_run_id, wait_id);
ALTER TABLE ONLY public.workflow_runs
    ADD CONSTRAINT workflow_runs_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_runs
    ADD CONSTRAINT workflow_runs_workflow_instance_id_run_number_unique UNIQUE (workflow_instance_id, run_number);
ALTER TABLE ONLY public.workflow_schedule_history_events
    ADD CONSTRAINT workflow_schedule_history_events_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_schedules
    ADD CONSTRAINT workflow_schedules_namespace_schedule_id_unique UNIQUE (namespace, schedule_id);
ALTER TABLE ONLY public.workflow_schedules
    ADD CONSTRAINT workflow_schedules_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_search_attributes
    ADD CONSTRAINT workflow_search_attributes_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_search_attributes
    ADD CONSTRAINT workflow_search_attrs_run_key_unique UNIQUE (workflow_run_id, key);
ALTER TABLE ONLY public.workflow_service_calls
    ADD CONSTRAINT workflow_service_calls_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_service_endpoints
    ADD CONSTRAINT workflow_service_endpoints_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_service_operations
    ADD CONSTRAINT workflow_service_operations_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_services
    ADD CONSTRAINT workflow_services_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_signal_records
    ADD CONSTRAINT workflow_signal_records_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_signal_records
    ADD CONSTRAINT workflow_signal_records_workflow_command_id_unique UNIQUE (workflow_command_id);
ALTER TABLE ONLY public.workflow_task_poll_cursors
    ADD CONSTRAINT workflow_task_poll_cursors_pkey PRIMARY KEY (namespace, task_queue);
ALTER TABLE ONLY public.workflow_tasks
    ADD CONSTRAINT workflow_tasks_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_update_validation_tasks
    ADD CONSTRAINT workflow_update_validation_tasks_idempotency_key_unique UNIQUE (idempotency_key);
ALTER TABLE ONLY public.workflow_update_validation_tasks
    ADD CONSTRAINT workflow_update_validation_tasks_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_updates
    ADD CONSTRAINT workflow_updates_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_updates
    ADD CONSTRAINT workflow_updates_workflow_command_id_unique UNIQUE (workflow_command_id);
ALTER TABLE ONLY public.workflow_worker_build_id_rollouts
    ADD CONSTRAINT workflow_worker_build_id_rollouts_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_worker_compatibility_heartbeats
    ADD CONSTRAINT workflow_worker_compatibility_heartbeats_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_worker_compatibility_heartbeats
    ADD CONSTRAINT workflow_worker_compatibility_heartbeats_scope_unique UNIQUE (worker_id, scope_key);
ALTER TABLE ONLY public.workflow_worker_registrations
    ADD CONSTRAINT workflow_worker_registrations_pkey PRIMARY KEY (id);
ALTER TABLE ONLY public.workflow_worker_registrations
    ADD CONSTRAINT workflow_worker_registrations_worker_id_namespace_unique UNIQUE (worker_id, namespace);
ALTER TABLE ONLY public.workflow_worker_sessions
    ADD CONSTRAINT workflow_worker_sessions_namespace_session_id_unique UNIQUE (namespace, session_id);
ALTER TABLE ONLY public.workflow_worker_sessions
    ADD CONSTRAINT workflow_worker_sessions_pkey PRIMARY KEY (id);
CREATE INDEX activity_attempts_activity_execution_id_index ON public.activity_attempts USING btree (activity_execution_id);
CREATE INDEX activity_attempts_workflow_run_id_index ON public.activity_attempts USING btree (workflow_run_id);
CREATE INDEX activity_attempts_workflow_task_id_index ON public.activity_attempts USING btree (workflow_task_id);
CREATE INDEX activity_exec_prom_metrics_series_idx ON public.activity_executions USING btree (workflow_run_id, queue, activity_type);
CREATE INDEX activity_executions_workflow_run_id_index ON public.activity_executions USING btree (workflow_run_id);
CREATE INDEX child_calls_child_parent ON public.workflow_child_calls USING btree (resolved_child_instance_id, parent_workflow_run_id);
CREATE INDEX child_calls_parent_seq ON public.workflow_child_calls USING btree (parent_workflow_run_id, sequence);
CREATE INDEX child_calls_parent_status ON public.workflow_child_calls USING btree (parent_workflow_run_id, status);
CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);
CREATE INDEX runtime_credentials_expires_at_index ON public.runtime_credentials USING btree (expires_at);
CREATE INDEX runtime_credentials_revoked_at_index ON public.runtime_credentials USING btree (revoked_at);
CREATE INDEX runtime_credentials_tenant_index ON public.runtime_credentials USING btree (tenant);
CREATE INDEX runtime_external_payloads_expires_at_index ON public.runtime_external_payloads USING btree (expires_at);
CREATE INDEX runtime_external_payloads_namespace_index ON public.runtime_external_payloads USING btree (namespace);
CREATE INDEX runtime_external_payloads_reclamation_idx ON public.runtime_external_payloads USING btree (retained_at, expires_at, upload_status);
CREATE INDEX runtime_payload_completion_budgets_expires_at_index ON public.runtime_payload_completion_budgets USING btree (expires_at);
CREATE INDEX runtime_payload_completion_budgets_namespace_index ON public.runtime_payload_completion_budgets USING btree (namespace);
CREATE INDEX search_attribute_definitions_namespace_index ON public.search_attribute_definitions USING btree (namespace);
CREATE INDEX wf_durable_stream_items_run_name_offset_idx ON public.workflow_durable_stream_items USING btree (workflow_run_id, stream_name, "offset");
CREATE INDEX wf_durable_streams_ns_instance_name_idx ON public.workflow_durable_streams USING btree (namespace, workflow_instance_id, stream_name);
CREATE INDEX wf_durable_streams_ns_status_idx ON public.workflow_durable_streams USING btree (namespace, status);
CREATE INDEX wf_inbound_items_ns_instance_name_position_idx ON public.workflow_inbound_stream_items USING btree (namespace, workflow_instance_id, stream_name, "position");
CREATE INDEX wf_msgs_instance_stream_seq ON public.workflow_messages USING btree (workflow_instance_id, stream_key, sequence);
CREATE INDEX wf_msgs_run_dir_state ON public.workflow_messages USING btree (workflow_run_id, direction, consume_state);
CREATE INDEX wf_msgs_stream_seq_state ON public.workflow_messages USING btree (stream_key, sequence, consume_state);
CREATE INDEX wf_msgs_target_state ON public.workflow_messages USING btree (target_workflow_instance_id, consume_state);
CREATE INDEX wf_schedule_history_event_recorded_idx ON public.workflow_schedule_history_events USING btree (event_type, recorded_at);
CREATE INDEX wf_schedule_history_instance_idx ON public.workflow_schedule_history_events USING btree (workflow_instance_id);
CREATE INDEX wf_schedule_history_namespace_idx ON public.workflow_schedule_history_events USING btree (namespace);
CREATE INDEX wf_schedule_history_namespace_schedule_idx ON public.workflow_schedule_history_events USING btree (namespace, schedule_id);
CREATE INDEX wf_schedule_history_occurrence_idx ON public.workflow_schedule_history_events USING btree (workflow_schedule_id, event_type, occurrence_at_utc);
CREATE INDEX wf_schedule_history_run_idx ON public.workflow_schedule_history_events USING btree (workflow_run_id);
CREATE INDEX wf_schedule_history_schedule_idx ON public.workflow_schedule_history_events USING btree (schedule_id);
CREATE INDEX wf_schedule_history_workflow_schedule_idx ON public.workflow_schedule_history_events USING btree (workflow_schedule_id);
CREATE INDEX wf_service_calls_accepted_at_idx ON public.workflow_service_calls USING btree (accepted_at);
CREATE INDEX wf_service_calls_binding_kind_idx ON public.workflow_service_calls USING btree (resolved_binding_kind);
CREATE INDEX wf_service_calls_caller_instance_idx ON public.workflow_service_calls USING btree (caller_workflow_instance_id);
CREATE INDEX wf_service_calls_caller_namespace_idx ON public.workflow_service_calls USING btree (caller_namespace);
CREATE INDEX wf_service_calls_caller_outcome_idx ON public.workflow_service_calls USING btree (caller_namespace, outcome);
CREATE INDEX wf_service_calls_caller_run_idx ON public.workflow_service_calls USING btree (caller_workflow_run_id);
CREATE INDEX wf_service_calls_caller_status_idx ON public.workflow_service_calls USING btree (caller_namespace, status);
CREATE INDEX wf_service_calls_endpoint_idx ON public.workflow_service_calls USING btree (workflow_service_endpoint_id);
CREATE INDEX wf_service_calls_idempotency_idx ON public.workflow_service_calls USING btree (idempotency_key);
CREATE INDEX wf_service_calls_linked_instance_idx ON public.workflow_service_calls USING btree (linked_workflow_instance_id);
CREATE INDEX wf_service_calls_linked_run_idx ON public.workflow_service_calls USING btree (linked_workflow_run_id);
CREATE INDEX wf_service_calls_linked_update_idx ON public.workflow_service_calls USING btree (linked_workflow_update_id);
CREATE INDEX wf_service_calls_mode_idx ON public.workflow_service_calls USING btree (operation_mode);
CREATE INDEX wf_service_calls_namespace_idx ON public.workflow_service_calls USING btree (namespace);
CREATE INDEX wf_service_calls_namespace_outcome_idx ON public.workflow_service_calls USING btree (namespace, outcome);
CREATE INDEX wf_service_calls_namespace_status_idx ON public.workflow_service_calls USING btree (namespace, status);
CREATE INDEX wf_service_calls_operation_idx ON public.workflow_service_calls USING btree (workflow_service_operation_id);
CREATE INDEX wf_service_calls_outcome_category_idx ON public.workflow_service_calls USING btree (outcome_category);
CREATE INDEX wf_service_calls_outcome_idx ON public.workflow_service_calls USING btree (outcome);
CREATE INDEX wf_service_calls_policy_idx ON public.workflow_service_calls USING btree (policy_name);
CREATE INDEX wf_service_calls_principal_subject_idx ON public.workflow_service_calls USING btree (caller_principal_subject);
CREATE INDEX wf_service_calls_service_idx ON public.workflow_service_calls USING btree (workflow_service_id);
CREATE INDEX wf_service_calls_status_idx ON public.workflow_service_calls USING btree (status);
CREATE INDEX wf_service_calls_target_namespace_idx ON public.workflow_service_calls USING btree (target_namespace);
CREATE INDEX wf_service_calls_target_outcome_idx ON public.workflow_service_calls USING btree (target_namespace, outcome);
CREATE INDEX wf_service_calls_target_status_idx ON public.workflow_service_calls USING btree (target_namespace, status);
CREATE INDEX wf_service_endpoints_name_idx ON public.workflow_service_endpoints USING btree (endpoint_name);
CREATE INDEX wf_service_endpoints_namespace_idx ON public.workflow_service_endpoints USING btree (namespace);
CREATE INDEX wf_service_ops_binding_kind_idx ON public.workflow_service_operations USING btree (handler_binding_kind);
CREATE INDEX wf_service_ops_endpoint_idx ON public.workflow_service_operations USING btree (workflow_service_endpoint_id);
CREATE INDEX wf_service_ops_mode_idx ON public.workflow_service_operations USING btree (operation_mode);
CREATE INDEX wf_service_ops_name_idx ON public.workflow_service_operations USING btree (operation_name);
CREATE INDEX wf_service_ops_namespace_idx ON public.workflow_service_operations USING btree (namespace);
CREATE INDEX wf_service_ops_namespace_name_idx ON public.workflow_service_operations USING btree (namespace, operation_name);
CREATE INDEX wf_service_ops_service_idx ON public.workflow_service_operations USING btree (workflow_service_id);
CREATE INDEX wf_services_endpoint_idx ON public.workflow_services USING btree (workflow_service_endpoint_id);
CREATE INDEX wf_services_name_idx ON public.workflow_services USING btree (service_name);
CREATE INDEX wf_services_namespace_idx ON public.workflow_services USING btree (namespace);
CREATE INDEX wf_services_namespace_name_idx ON public.workflow_services USING btree (namespace, service_name);
CREATE INDEX wfrs_decl_contract_source_idx ON public.workflow_run_summaries USING btree (declared_contract_source);
CREATE INDEX wfrs_decl_entry_mode_idx ON public.workflow_run_summaries USING btree (declared_entry_mode);
CREATE INDEX wfrs_namespace_sort_idx ON public.workflow_run_summaries USING btree (namespace, sort_timestamp, id);
CREATE INDEX wfrs_namespace_status_sort_idx ON public.workflow_run_summaries USING btree (namespace, status_bucket, sort_timestamp, id);
CREATE INDEX wfrs_namespace_type_sort_idx ON public.workflow_run_summaries USING btree (namespace, workflow_type, sort_timestamp, id);
CREATE INDEX wfrs_prom_metrics_series_idx ON public.workflow_run_summaries USING btree (namespace, queue, workflow_type);
CREATE INDEX workflow_child_calls_parent_workflow_instance_id_index ON public.workflow_child_calls USING btree (parent_workflow_instance_id);
CREATE INDEX workflow_child_calls_parent_workflow_run_id_index ON public.workflow_child_calls USING btree (parent_workflow_run_id);
CREATE INDEX workflow_child_calls_resolved_child_instance_id_index ON public.workflow_child_calls USING btree (resolved_child_instance_id);
CREATE INDEX workflow_child_calls_resolved_child_run_id_index ON public.workflow_child_calls USING btree (resolved_child_run_id);
CREATE INDEX workflow_child_calls_sequence_index ON public.workflow_child_calls USING btree (sequence);
CREATE INDEX workflow_child_calls_status_index ON public.workflow_child_calls USING btree (status);
CREATE INDEX workflow_child_projection_repairs_drain_idx ON public.workflow_child_projection_repairs USING btree (workflow_run_id, workflow_task_id, history_sequence);
CREATE INDEX workflow_child_projection_repairs_failure_idx ON public.workflow_child_projection_repairs USING btree (workflow_run_id, failure_id);
CREATE INDEX workflow_commands_command_sequence_index ON public.workflow_commands USING btree (command_sequence);
CREATE INDEX workflow_commands_command_type_index ON public.workflow_commands USING btree (command_type);
CREATE INDEX workflow_commands_requested_workflow_run_id_index ON public.workflow_commands USING btree (requested_workflow_run_id);
CREATE INDEX workflow_commands_resolved_workflow_run_id_index ON public.workflow_commands USING btree (resolved_workflow_run_id);
CREATE INDEX workflow_commands_source_index ON public.workflow_commands USING btree (source);
CREATE INDEX workflow_commands_status_index ON public.workflow_commands USING btree (status);
CREATE INDEX workflow_commands_workflow_instance_id_index ON public.workflow_commands USING btree (workflow_instance_id);
CREATE INDEX workflow_commands_workflow_run_id_index ON public.workflow_commands USING btree (workflow_run_id);
CREATE INDEX workflow_durable_stream_items_namespace_index ON public.workflow_durable_stream_items USING btree (namespace);
CREATE INDEX workflow_durable_stream_items_workflow_run_id_index ON public.workflow_durable_stream_items USING btree (workflow_run_id);
CREATE INDEX workflow_durable_streams_namespace_index ON public.workflow_durable_streams USING btree (namespace);
CREATE INDEX workflow_durable_streams_status_index ON public.workflow_durable_streams USING btree (status);
CREATE INDEX workflow_durable_streams_workflow_instance_id_index ON public.workflow_durable_streams USING btree (workflow_instance_id);
CREATE INDEX workflow_durable_streams_workflow_run_id_index ON public.workflow_durable_streams USING btree (workflow_run_id);
CREATE INDEX workflow_failures_failure_category_index ON public.workflow_failures USING btree (failure_category);
CREATE INDEX workflow_failures_source_kind_source_id_index ON public.workflow_failures USING btree (source_kind, source_id);
CREATE INDEX workflow_failures_workflow_run_id_index ON public.workflow_failures USING btree (workflow_run_id);
CREATE INDEX workflow_history_events_workflow_command_id_index ON public.workflow_history_events USING btree (workflow_command_id);
CREATE INDEX workflow_history_events_workflow_run_id_index ON public.workflow_history_events USING btree (workflow_run_id);
CREATE INDEX workflow_history_events_workflow_task_id_index ON public.workflow_history_events USING btree (workflow_task_id);
CREATE INDEX workflow_inbound_stream_items_consumed_run_id_index ON public.workflow_inbound_stream_items USING btree (consumed_run_id);
CREATE INDEX workflow_inbound_stream_items_consumed_task_id_index ON public.workflow_inbound_stream_items USING btree (consumed_task_id);
CREATE INDEX workflow_inbound_stream_items_delivered_run_id_index ON public.workflow_inbound_stream_items USING btree (delivered_run_id);
CREATE INDEX workflow_inbound_stream_items_namespace_index ON public.workflow_inbound_stream_items USING btree (namespace);
CREATE INDEX workflow_inbound_stream_items_payload_released_at_index ON public.workflow_inbound_stream_items USING btree (payload_released_at);
CREATE INDEX workflow_inbound_stream_items_workflow_command_id_index ON public.workflow_inbound_stream_items USING btree (workflow_command_id);
CREATE INDEX workflow_inbound_stream_items_workflow_instance_id_index ON public.workflow_inbound_stream_items USING btree (workflow_instance_id);
CREATE INDEX workflow_inbound_streams_cleanup_blocked_run_id_index ON public.workflow_inbound_streams USING btree (cleanup_blocked_run_id);
CREATE INDEX workflow_inbound_streams_cursor_checkpoint_run_id_index ON public.workflow_inbound_streams USING btree (cursor_checkpoint_run_id);
CREATE INDEX workflow_inbound_streams_namespace_index ON public.workflow_inbound_streams USING btree (namespace);
CREATE INDEX workflow_inbound_streams_waiting_run_id_index ON public.workflow_inbound_streams USING btree (waiting_run_id);
CREATE INDEX workflow_inbound_streams_workflow_instance_id_index ON public.workflow_inbound_streams USING btree (workflow_instance_id);
CREATE INDEX workflow_instances_business_key_index ON public.workflow_instances USING btree (business_key);
CREATE INDEX workflow_instances_current_run_id_index ON public.workflow_instances USING btree (current_run_id);
CREATE INDEX workflow_instances_namespace_index ON public.workflow_instances USING btree (namespace);
CREATE INDEX workflow_links_child_workflow_instance_id_index ON public.workflow_links USING btree (child_workflow_instance_id);
CREATE INDEX workflow_links_child_workflow_run_id_index ON public.workflow_links USING btree (child_workflow_run_id);
CREATE INDEX workflow_links_parent_sequence_type_index ON public.workflow_links USING btree (parent_workflow_run_id, sequence, link_type);
CREATE INDEX workflow_links_parent_workflow_instance_id_index ON public.workflow_links USING btree (parent_workflow_instance_id);
CREATE INDEX workflow_links_parent_workflow_run_id_index ON public.workflow_links USING btree (parent_workflow_run_id);
CREATE INDEX workflow_memos_instance_key ON public.workflow_memos USING btree (workflow_instance_id, key);
CREATE INDEX workflow_messages_channel_index ON public.workflow_messages USING btree (channel);
CREATE INDEX workflow_messages_consume_state_index ON public.workflow_messages USING btree (consume_state);
CREATE INDEX workflow_messages_correlation_id_index ON public.workflow_messages USING btree (correlation_id);
CREATE INDEX workflow_messages_direction_index ON public.workflow_messages USING btree (direction);
CREATE INDEX workflow_messages_idempotency_key_index ON public.workflow_messages USING btree (idempotency_key);
CREATE INDEX workflow_messages_sequence_index ON public.workflow_messages USING btree (sequence);
CREATE INDEX workflow_messages_source_workflow_instance_id_index ON public.workflow_messages USING btree (source_workflow_instance_id);
CREATE INDEX workflow_messages_stream_key_index ON public.workflow_messages USING btree (stream_key);
CREATE INDEX workflow_messages_target_workflow_instance_id_index ON public.workflow_messages USING btree (target_workflow_instance_id);
CREATE INDEX workflow_messages_workflow_instance_id_index ON public.workflow_messages USING btree (workflow_instance_id);
CREATE INDEX workflow_messages_workflow_run_id_index ON public.workflow_messages USING btree (workflow_run_id);
CREATE INDEX workflow_run_lineage_entries_child_call_id_index ON public.workflow_run_lineage_entries USING btree (child_call_id);
CREATE INDEX workflow_run_lineage_entries_closed_reason_index ON public.workflow_run_lineage_entries USING btree (closed_reason);
CREATE INDEX workflow_run_lineage_entries_direction_index ON public.workflow_run_lineage_entries USING btree (direction);
CREATE INDEX workflow_run_lineage_entries_is_primary_parent_index ON public.workflow_run_lineage_entries USING btree (is_primary_parent);
CREATE INDEX workflow_run_lineage_entries_link_type_index ON public.workflow_run_lineage_entries USING btree (link_type);
CREATE INDEX workflow_run_lineage_entries_linked_at_index ON public.workflow_run_lineage_entries USING btree (linked_at);
CREATE INDEX workflow_run_lineage_entries_related_workflow_instance_id_index ON public.workflow_run_lineage_entries USING btree (related_workflow_instance_id);
CREATE INDEX workflow_run_lineage_entries_related_workflow_run_id_index ON public.workflow_run_lineage_entries USING btree (related_workflow_run_id);
CREATE INDEX workflow_run_lineage_entries_related_workflow_type_index ON public.workflow_run_lineage_entries USING btree (related_workflow_type);
CREATE INDEX workflow_run_lineage_entries_sequence_index ON public.workflow_run_lineage_entries USING btree (sequence);
CREATE INDEX workflow_run_lineage_entries_status_bucket_index ON public.workflow_run_lineage_entries USING btree (status_bucket);
CREATE INDEX workflow_run_lineage_entries_status_index ON public.workflow_run_lineage_entries USING btree (status);
CREATE INDEX workflow_run_lineage_entries_workflow_instance_id_index ON public.workflow_run_lineage_entries USING btree (workflow_instance_id);
CREATE INDEX workflow_run_lineage_entries_workflow_run_id_index ON public.workflow_run_lineage_entries USING btree (workflow_run_id);
CREATE INDEX workflow_run_lineage_instance_direction_type_index ON public.workflow_run_lineage_entries USING btree (workflow_instance_id, direction, link_type);
CREATE INDEX workflow_run_lineage_run_direction_position_index ON public.workflow_run_lineage_entries USING btree (workflow_run_id, direction, "position");
CREATE INDEX workflow_run_summaries_archive_command_id_index ON public.workflow_run_summaries USING btree (archive_command_id);
CREATE INDEX workflow_run_summaries_archived_at_index ON public.workflow_run_summaries USING btree (archived_at);
CREATE INDEX workflow_run_summaries_business_key_index ON public.workflow_run_summaries USING btree (business_key);
CREATE INDEX workflow_run_summaries_continue_as_new_recommended_index ON public.workflow_run_summaries USING btree (continue_as_new_recommended);
CREATE INDEX workflow_run_summaries_history_budget_pressure_index ON public.workflow_run_summaries USING btree (history_budget_pressure);
CREATE INDEX workflow_run_summaries_is_current_run_index ON public.workflow_run_summaries USING btree (is_current_run);
CREATE INDEX workflow_run_summaries_namespace_index ON public.workflow_run_summaries USING btree (namespace);
CREATE INDEX workflow_run_summaries_projection_schema_version_index ON public.workflow_run_summaries USING btree (projection_schema_version);
CREATE INDEX workflow_run_summaries_repair_attention_index ON public.workflow_run_summaries USING btree (repair_attention);
CREATE INDEX workflow_run_summaries_repair_blocked_reason_index ON public.workflow_run_summaries USING btree (repair_blocked_reason);
CREATE INDEX workflow_run_summaries_sort_key_index ON public.workflow_run_summaries USING btree (sort_key);
CREATE INDEX workflow_run_summaries_sort_order_index ON public.workflow_run_summaries USING btree (sort_timestamp, id);
CREATE INDEX workflow_run_summaries_status_bucket_index ON public.workflow_run_summaries USING btree (status_bucket);
CREATE INDEX workflow_run_summaries_status_bucket_started_at_index ON public.workflow_run_summaries USING btree (status_bucket, started_at);
CREATE INDEX workflow_run_summaries_status_index ON public.workflow_run_summaries USING btree (status);
CREATE INDEX workflow_run_summaries_workflow_instance_id_index ON public.workflow_run_summaries USING btree (workflow_instance_id);
CREATE INDEX workflow_run_timeline_entries_activity_execution_id_index ON public.workflow_run_timeline_entries USING btree (activity_execution_id);
CREATE INDEX workflow_run_timeline_entries_command_id_index ON public.workflow_run_timeline_entries USING btree (command_id);
CREATE INDEX workflow_run_timeline_entries_command_sequence_index ON public.workflow_run_timeline_entries USING btree (command_sequence);
CREATE INDEX workflow_run_timeline_entries_failure_id_index ON public.workflow_run_timeline_entries USING btree (failure_id);
CREATE INDEX workflow_run_timeline_entries_history_event_id_index ON public.workflow_run_timeline_entries USING btree (history_event_id);
CREATE INDEX workflow_run_timeline_entries_kind_index ON public.workflow_run_timeline_entries USING btree (kind);
CREATE INDEX workflow_run_timeline_entries_recorded_at_index ON public.workflow_run_timeline_entries USING btree (recorded_at);
CREATE INDEX workflow_run_timeline_entries_sequence_index ON public.workflow_run_timeline_entries USING btree (sequence);
CREATE INDEX workflow_run_timeline_entries_source_id_index ON public.workflow_run_timeline_entries USING btree (source_id);
CREATE INDEX workflow_run_timeline_entries_source_kind_index ON public.workflow_run_timeline_entries USING btree (source_kind);
CREATE INDEX workflow_run_timeline_entries_task_id_index ON public.workflow_run_timeline_entries USING btree (task_id);
CREATE INDEX workflow_run_timeline_entries_timer_id_index ON public.workflow_run_timeline_entries USING btree (timer_id);
CREATE INDEX workflow_run_timeline_entries_type_index ON public.workflow_run_timeline_entries USING btree (type);
CREATE INDEX workflow_run_timeline_entries_workflow_instance_id_index ON public.workflow_run_timeline_entries USING btree (workflow_instance_id);
CREATE INDEX workflow_run_timeline_entries_workflow_run_id_index ON public.workflow_run_timeline_entries USING btree (workflow_run_id);
CREATE INDEX workflow_run_timeline_instance_kind_recorded_index ON public.workflow_run_timeline_entries USING btree (workflow_instance_id, kind, recorded_at);
CREATE INDEX workflow_run_timeline_run_sequence_index ON public.workflow_run_timeline_entries USING btree (workflow_run_id, sequence);
CREATE INDEX workflow_run_timer_entries_condition_key_index ON public.workflow_run_timer_entries USING btree (condition_key);
CREATE INDEX workflow_run_timer_entries_condition_wait_id_index ON public.workflow_run_timer_entries USING btree (condition_wait_id);
CREATE INDEX workflow_run_timer_entries_history_authority_index ON public.workflow_run_timer_entries USING btree (history_authority);
CREATE INDEX workflow_run_timer_entries_instance_kind_status_index ON public.workflow_run_timer_entries USING btree (workflow_instance_id, timer_kind, status);
CREATE INDEX workflow_run_timer_entries_run_status_position_index ON public.workflow_run_timer_entries USING btree (workflow_run_id, status, "position");
CREATE INDEX workflow_run_timer_entries_sequence_index ON public.workflow_run_timer_entries USING btree (sequence);
CREATE INDEX workflow_run_timer_entries_source_status_index ON public.workflow_run_timer_entries USING btree (source_status);
CREATE INDEX workflow_run_timer_entries_status_index ON public.workflow_run_timer_entries USING btree (status);
CREATE INDEX workflow_run_timer_entries_timer_kind_index ON public.workflow_run_timer_entries USING btree (timer_kind);
CREATE INDEX workflow_run_timer_entries_workflow_instance_id_index ON public.workflow_run_timer_entries USING btree (workflow_instance_id);
CREATE INDEX workflow_run_timer_entries_workflow_run_id_index ON public.workflow_run_timer_entries USING btree (workflow_run_id);
CREATE INDEX workflow_run_timers_status_fire_at_index ON public.workflow_run_timers USING btree (status, fire_at);
CREATE INDEX workflow_run_timers_workflow_run_id_index ON public.workflow_run_timers USING btree (workflow_run_id);
CREATE INDEX workflow_run_waits_command_id_index ON public.workflow_run_waits USING btree (command_id);
CREATE INDEX workflow_run_waits_history_authority_index ON public.workflow_run_waits USING btree (history_authority);
CREATE INDEX workflow_run_waits_instance_kind_status_index ON public.workflow_run_waits USING btree (workflow_instance_id, kind, status);
CREATE INDEX workflow_run_waits_kind_index ON public.workflow_run_waits USING btree (kind);
CREATE INDEX workflow_run_waits_resume_source_id_index ON public.workflow_run_waits USING btree (resume_source_id);
CREATE INDEX workflow_run_waits_resume_source_kind_index ON public.workflow_run_waits USING btree (resume_source_kind);
CREATE INDEX workflow_run_waits_run_status_position_index ON public.workflow_run_waits USING btree (workflow_run_id, status, "position");
CREATE INDEX workflow_run_waits_sequence_index ON public.workflow_run_waits USING btree (sequence);
CREATE INDEX workflow_run_waits_source_status_index ON public.workflow_run_waits USING btree (source_status);
CREATE INDEX workflow_run_waits_status_index ON public.workflow_run_waits USING btree (status);
CREATE INDEX workflow_run_waits_target_name_index ON public.workflow_run_waits USING btree (target_name);
CREATE INDEX workflow_run_waits_task_backed_index ON public.workflow_run_waits USING btree (task_backed);
CREATE INDEX workflow_run_waits_task_id_index ON public.workflow_run_waits USING btree (task_id);
CREATE INDEX workflow_run_waits_workflow_instance_id_index ON public.workflow_run_waits USING btree (workflow_instance_id);
CREATE INDEX workflow_run_waits_workflow_run_id_index ON public.workflow_run_waits USING btree (workflow_run_id);
CREATE INDEX workflow_runs_archive_command_id_index ON public.workflow_runs USING btree (archive_command_id);
CREATE INDEX workflow_runs_archived_at_index ON public.workflow_runs USING btree (archived_at);
CREATE INDEX workflow_runs_business_key_index ON public.workflow_runs USING btree (business_key);
CREATE INDEX workflow_runs_cancellation_request_command_id_index ON public.workflow_runs USING btree (cancellation_request_command_id);
CREATE INDEX workflow_runs_import_dedupe_key_index ON public.workflow_runs USING btree (import_dedupe_key);
CREATE INDEX workflow_runs_import_id_index ON public.workflow_runs USING btree (import_id);
CREATE INDEX workflow_runs_import_source_index ON public.workflow_runs USING btree (import_source);
CREATE INDEX workflow_runs_imported_at_index ON public.workflow_runs USING btree (imported_at);
CREATE INDEX workflow_runs_namespace_index ON public.workflow_runs USING btree (namespace);
CREATE INDEX workflow_runs_namespace_status_quota_idx ON public.workflow_runs USING btree (namespace, status);
CREATE INDEX workflow_runs_prom_metrics_queue_idx ON public.workflow_runs USING btree (namespace, queue);
CREATE INDEX workflow_runs_sticky_until_index ON public.workflow_runs USING btree (sticky_until);
CREATE INDEX workflow_runs_sticky_worker_id_index ON public.workflow_runs USING btree (sticky_worker_id);
CREATE INDEX workflow_runs_workflow_instance_id_index ON public.workflow_runs USING btree (workflow_instance_id);
CREATE INDEX workflow_schedules_latest_workflow_instance_id_index ON public.workflow_schedules USING btree (latest_workflow_instance_id);
CREATE INDEX workflow_schedules_namespace_index ON public.workflow_schedules USING btree (namespace);
CREATE INDEX workflow_schedules_status_next_fire_at_index ON public.workflow_schedules USING btree (status, next_fire_at);
CREATE INDEX workflow_search_attributes_value_bool_index ON public.workflow_search_attributes USING btree (value_bool);
CREATE INDEX workflow_search_attributes_value_datetime_index ON public.workflow_search_attributes USING btree (value_datetime);
CREATE INDEX workflow_search_attributes_value_float_index ON public.workflow_search_attributes USING btree (value_float);
CREATE INDEX workflow_search_attributes_value_int_index ON public.workflow_search_attributes USING btree (value_int);
CREATE INDEX workflow_search_attributes_value_keyword_index ON public.workflow_search_attributes USING btree (value_keyword);
CREATE INDEX workflow_search_attributes_workflow_instance_id_index ON public.workflow_search_attributes USING btree (workflow_instance_id);
CREATE INDEX workflow_search_attributes_workflow_run_id_index ON public.workflow_search_attributes USING btree (workflow_run_id);
CREATE INDEX workflow_search_attrs_instance_key_type ON public.workflow_search_attributes USING btree (workflow_instance_id, key, type);
CREATE INDEX workflow_search_attrs_key_bool ON public.workflow_search_attributes USING btree (key, value_bool);
CREATE INDEX workflow_search_attrs_key_datetime ON public.workflow_search_attributes USING btree (key, value_datetime);
CREATE INDEX workflow_search_attrs_key_float ON public.workflow_search_attributes USING btree (key, value_float);
CREATE INDEX workflow_search_attrs_key_int ON public.workflow_search_attributes USING btree (key, value_int);
CREATE INDEX workflow_search_attrs_key_keyword ON public.workflow_search_attributes USING btree (key, value_keyword);
CREATE INDEX workflow_signal_records_command_sequence_index ON public.workflow_signal_records USING btree (command_sequence);
CREATE INDEX workflow_signal_records_outcome_index ON public.workflow_signal_records USING btree (outcome);
CREATE INDEX workflow_signal_records_requested_workflow_run_id_index ON public.workflow_signal_records USING btree (requested_workflow_run_id);
CREATE INDEX workflow_signal_records_resolved_workflow_run_id_index ON public.workflow_signal_records USING btree (resolved_workflow_run_id);
CREATE INDEX workflow_signal_records_signal_name_index ON public.workflow_signal_records USING btree (signal_name);
CREATE INDEX workflow_signal_records_signal_wait_id_index ON public.workflow_signal_records USING btree (signal_wait_id);
CREATE INDEX workflow_signal_records_status_index ON public.workflow_signal_records USING btree (status);
CREATE INDEX workflow_signal_records_workflow_instance_id_index ON public.workflow_signal_records USING btree (workflow_instance_id);
CREATE INDEX workflow_signal_records_workflow_run_id_index ON public.workflow_signal_records USING btree (workflow_run_id);
CREATE INDEX workflow_signal_records_workflow_sequence_index ON public.workflow_signal_records USING btree (workflow_sequence);
CREATE INDEX workflow_tasks_dispatch_order_index ON public.workflow_tasks USING btree (queue, status, priority, available_at);
CREATE INDEX workflow_tasks_fairness_class_index ON public.workflow_tasks USING btree (queue, status, fairness_key);
CREATE INDEX workflow_tasks_namespace_index ON public.workflow_tasks USING btree (namespace);
CREATE INDEX workflow_tasks_namespace_queue_status_idx ON public.workflow_tasks USING btree (namespace, queue, status);
CREATE INDEX workflow_tasks_prom_metrics_queue_idx ON public.workflow_tasks USING btree (namespace, queue);
CREATE INDEX workflow_tasks_status_available_at_index ON public.workflow_tasks USING btree (status, available_at);
CREATE INDEX workflow_tasks_sticky_claimed_at_index ON public.workflow_tasks USING btree (sticky_claimed_at);
CREATE INDEX workflow_tasks_sticky_replay_mode_index ON public.workflow_tasks USING btree (sticky_replay_mode);
CREATE INDEX workflow_tasks_sticky_until_index ON public.workflow_tasks USING btree (sticky_until);
CREATE INDEX workflow_tasks_sticky_worker_id_index ON public.workflow_tasks USING btree (sticky_worker_id);
CREATE INDEX workflow_tasks_workflow_run_id_index ON public.workflow_tasks USING btree (workflow_run_id);
CREATE INDEX workflow_update_validation_poll_index ON public.workflow_update_validation_tasks USING btree (namespace, task_queue, status, created_at);
CREATE INDEX workflow_update_validation_tasks_compatibility_index ON public.workflow_update_validation_tasks USING btree (compatibility);
CREATE INDEX workflow_update_validation_tasks_lease_expires_at_index ON public.workflow_update_validation_tasks USING btree (lease_expires_at);
CREATE INDEX workflow_update_validation_tasks_lease_owner_index ON public.workflow_update_validation_tasks USING btree (lease_owner);
CREATE INDEX workflow_update_validation_tasks_namespace_index ON public.workflow_update_validation_tasks USING btree (namespace);
CREATE INDEX workflow_update_validation_tasks_status_index ON public.workflow_update_validation_tasks USING btree (status);
CREATE INDEX workflow_update_validation_tasks_task_queue_index ON public.workflow_update_validation_tasks USING btree (task_queue);
CREATE INDEX workflow_update_validation_tasks_update_name_index ON public.workflow_update_validation_tasks USING btree (update_name);
CREATE INDEX workflow_update_validation_tasks_workflow_instance_id_index ON public.workflow_update_validation_tasks USING btree (workflow_instance_id);
CREATE INDEX workflow_update_validation_tasks_workflow_run_id_index ON public.workflow_update_validation_tasks USING btree (workflow_run_id);
CREATE INDEX workflow_update_validation_tasks_workflow_type_index ON public.workflow_update_validation_tasks USING btree (workflow_type);
CREATE INDEX workflow_updates_command_sequence_index ON public.workflow_updates USING btree (command_sequence);
CREATE INDEX workflow_updates_failure_id_index ON public.workflow_updates USING btree (failure_id);
CREATE INDEX workflow_updates_outcome_index ON public.workflow_updates USING btree (outcome);
CREATE INDEX workflow_updates_requested_workflow_run_id_index ON public.workflow_updates USING btree (requested_workflow_run_id);
CREATE INDEX workflow_updates_resolved_workflow_run_id_index ON public.workflow_updates USING btree (resolved_workflow_run_id);
CREATE INDEX workflow_updates_status_index ON public.workflow_updates USING btree (status);
CREATE INDEX workflow_updates_update_name_index ON public.workflow_updates USING btree (update_name);
CREATE INDEX workflow_updates_workflow_instance_id_index ON public.workflow_updates USING btree (workflow_instance_id);
CREATE INDEX workflow_updates_workflow_run_id_index ON public.workflow_updates USING btree (workflow_run_id);
CREATE INDEX workflow_updates_workflow_sequence_index ON public.workflow_updates USING btree (workflow_sequence);
CREATE INDEX workflow_worker_compatibility_heartbeats_connection_index ON public.workflow_worker_compatibility_heartbeats USING btree (connection);
CREATE INDEX workflow_worker_compatibility_heartbeats_expires_at_index ON public.workflow_worker_compatibility_heartbeats USING btree (expires_at);
CREATE INDEX workflow_worker_compatibility_heartbeats_namespace_index ON public.workflow_worker_compatibility_heartbeats USING btree (namespace);
CREATE INDEX workflow_worker_compatibility_heartbeats_queue_index ON public.workflow_worker_compatibility_heartbeats USING btree (queue);
CREATE INDEX workflow_worker_registrations_namespace_task_queue_status_index ON public.workflow_worker_registrations USING btree (namespace, task_queue, status);
CREATE INDEX workflow_worker_sessions_namespace_lease_owner_status_index ON public.workflow_worker_sessions USING btree (namespace, lease_owner, status);
CREATE INDEX workflow_worker_sessions_namespace_queue_status_index ON public.workflow_worker_sessions USING btree (namespace, queue, status);
CREATE INDEX workflow_worker_sessions_namespace_status_index ON public.workflow_worker_sessions USING btree (namespace, status);
ALTER TABLE ONLY public.workflow_child_calls
    ADD CONSTRAINT workflow_child_calls_parent_workflow_run_id_foreign FOREIGN KEY (parent_workflow_run_id) REFERENCES public.workflow_runs(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.workflow_durable_stream_items
    ADD CONSTRAINT workflow_durable_stream_items_stream_id_foreign FOREIGN KEY (stream_id) REFERENCES public.workflow_durable_streams(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.workflow_inbound_stream_items
    ADD CONSTRAINT workflow_inbound_stream_items_stream_id_foreign FOREIGN KEY (stream_id) REFERENCES public.workflow_inbound_streams(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.workflow_memos
    ADD CONSTRAINT workflow_memos_workflow_run_id_foreign FOREIGN KEY (workflow_run_id) REFERENCES public.workflow_runs(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.workflow_messages
    ADD CONSTRAINT workflow_messages_workflow_run_id_foreign FOREIGN KEY (workflow_run_id) REFERENCES public.workflow_runs(id) ON DELETE CASCADE;
ALTER TABLE ONLY public.workflow_search_attributes
    ADD CONSTRAINT workflow_search_attributes_workflow_run_id_foreign FOREIGN KEY (workflow_run_id) REFERENCES public.workflow_runs(id) ON DELETE CASCADE;

-- Native bookkeeping is separate from the existing PHP-owned columns.
CREATE TABLE public.dw_server_schema (engine text NOT NULL, version bigint NOT NULL);
INSERT INTO public.dw_server_schema VALUES ('rust-development', 2);
CREATE TABLE public.dw_task_completions (
    task_id character varying(26) PRIMARY KEY REFERENCES public.workflow_tasks(id) ON DELETE CASCADE,
    receipt jsonb NOT NULL
);
CREATE TABLE public.dw_poll_receipts (
    namespace text NOT NULL, worker_id text NOT NULL, kind text NOT NULL,
    request_id text NOT NULL,
    task_id character varying(26) NOT NULL REFERENCES public.workflow_tasks(id) ON DELETE CASCADE,
    attempt bigint NOT NULL, response jsonb NOT NULL,
    expires_at timestamp(6) without time zone NOT NULL,
    PRIMARY KEY(namespace,worker_id,kind,request_id)
);
CREATE INDEX dw_workflow_tasks_poll ON public.workflow_tasks(namespace,queue,task_type,status,available_at);
-- Generated from the frozen published PHP baseline on PostgreSQL 17.11.
-- pg_dump --schema-only --no-owner --no-privileges; dump session/psql
-- directives and comments are omitted. No table data is included.
