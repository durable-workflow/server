-- Physical schema only: no customer rows, credentials or sequence positions.
-- Definitions come from PostgreSQL itself, with search_path fixed to public.
SELECT kind, name, definition FROM (
    SELECT 'relation' AS kind, c.relname::text AS name,
        jsonb_build_object('kind', c.relkind, 'persistence', c.relpersistence,
            'row_security', c.relrowsecurity, 'force_row_security', c.relforcerowsecurity,
            'options', c.reloptions, 'partition', c.relispartition,
            'partition_key', pg_get_partkeydef(c.oid), 'access_method', am.amname,
            'view', CASE WHEN c.relkind IN ('v','m') THEN pg_get_viewdef(c.oid, false) END) AS definition
    FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
    LEFT JOIN pg_am am ON am.oid=c.relam
    WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','f','S')
    UNION ALL
    SELECT 'column', c.relname || '.' || a.attnum::text,
        jsonb_build_object('name', a.attname, 'type', format_type(a.atttypid,a.atttypmod),
            'not_null', a.attnotnull, 'default', pg_get_expr(d.adbin,d.adrelid,false),
            'identity', a.attidentity, 'generated', a.attgenerated,
            'collation', col.collname)
    FROM pg_attribute a JOIN pg_class c ON c.oid=a.attrelid
    JOIN pg_namespace n ON n.oid=c.relnamespace
    LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum
    LEFT JOIN pg_collation col ON col.oid=a.attcollation
    WHERE n.nspname='public' AND c.relkind IN ('r','p','v','m','f')
        AND a.attnum>0 AND NOT a.attisdropped
    UNION ALL
    SELECT 'constraint', c.relname || '.' || con.conname,
        jsonb_build_object('definition', pg_get_constraintdef(con.oid,false),
            'validated', con.convalidated, 'deferrable', con.condeferrable,
            'deferred', con.condeferred)
    FROM pg_constraint con JOIN pg_class c ON c.oid=con.conrelid
    JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public'
    UNION ALL
    SELECT 'index', idx.relname,
        jsonb_build_object('definition', pg_get_indexdef(i.indexrelid),
            'valid', i.indisvalid, 'ready', i.indisready,
            'unique', i.indisunique, 'primary', i.indisprimary)
    FROM pg_index i JOIN pg_class c ON c.oid=i.indrelid
    JOIN pg_class idx ON idx.oid=i.indexrelid
    JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public'
    UNION ALL
    SELECT 'sequence', c.relname,
        jsonb_build_object('type', format_type(s.seqtypid,NULL), 'start', s.seqstart,
            'min', s.seqmin, 'max', s.seqmax, 'increment', s.seqincrement,
            'cycle', s.seqcycle, 'cache', s.seqcache,
            'owned_by', owner.relname || '.' || a.attname)
    FROM pg_sequence s JOIN pg_class c ON c.oid=s.seqrelid
    JOIN pg_namespace n ON n.oid=c.relnamespace
    LEFT JOIN pg_depend dep ON dep.objid=c.oid AND dep.classid='pg_class'::regclass
        AND dep.refclassid='pg_class'::regclass AND dep.deptype IN ('a','i')
    LEFT JOIN pg_class owner ON owner.oid=dep.refobjid
    LEFT JOIN pg_attribute a ON a.attrelid=dep.refobjid AND a.attnum=dep.refobjsubid
    WHERE n.nspname='public'
    UNION ALL
    SELECT 'routine', p.proname || '(' || pg_get_function_identity_arguments(p.oid) || ')',
        jsonb_build_object('kind', p.prokind)
    FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public'
) AS catalog ORDER BY kind,name
