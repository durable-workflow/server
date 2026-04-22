<?php

namespace App\Support;

final class AuthCompositionContract
{
    public const SCHEMA = 'durable-workflow.v2.auth-composition.contract';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'scope' => 'external_execution_carriers',
            'unknown_field_policy' => 'ignore_additive_reject_unknown_required',
            'precedence' => [
                'connection_values' => [
                    'flag',
                    'environment',
                    'selected_profile',
                    'default',
                ],
                'profile_selection' => [
                    'flag_env',
                    'DW_ENV',
                    'current_profile',
                    'default_profile',
                ],
                'notes' => [
                    'flags win only for the current invocation',
                    'environment values are portable carrier inputs and must not be persisted by default',
                    'selected profile values are persisted configuration, but secret fields should be references',
                    'defaults are explicit and must appear in effective-config diagnostics',
                ],
            ],
            'canonical_environment' => [
                'server_url' => 'DURABLE_WORKFLOW_SERVER_URL',
                'namespace' => 'DURABLE_WORKFLOW_NAMESPACE',
                'auth_token' => 'DURABLE_WORKFLOW_AUTH_TOKEN',
                'tls_verify' => 'DURABLE_WORKFLOW_TLS_VERIFY',
                'profile' => 'DW_ENV',
            ],
            'auth_material' => [
                'token' => [
                    'status' => 'supported',
                    'transport' => 'bearer_authorization_header',
                    'persisted_as' => 'secret_reference_or_profile_env_reference',
                    'effective_config_value' => 'redacted',
                ],
                'mtls' => [
                    'status' => 'reserved',
                    'persisted_as' => 'certificate_and_key_references',
                    'effective_config_value' => 'references_only',
                ],
                'signed_headers' => [
                    'status' => 'reserved',
                    'persisted_as' => 'key_reference_and_header_allowlist',
                    'effective_config_value' => 'references_only',
                ],
                'extensions' => [
                    'status' => 'reserved',
                    'rule' => 'new auth material types must define redaction, persistence, and source precedence before use',
                ],
            ],
            'effective_config' => [
                'required_fields' => [
                    'server_url',
                    'namespace',
                    'profile',
                    'auth',
                    'tls',
                    'identity',
                ],
                'source_values' => [
                    'flag',
                    'environment',
                    'selected_profile',
                    'profile_env',
                    'default',
                    'server',
                ],
                'field_contracts' => [
                    'server_url' => [
                        'value' => 'normalized_absolute_url',
                        'source' => 'one_of_source_values',
                    ],
                    'namespace' => [
                        'value' => 'non_empty_namespace_name',
                        'source' => 'one_of_source_values',
                    ],
                    'profile' => [
                        'name' => 'nullable_string',
                        'source' => 'flag_env_or_DW_ENV_or_current_profile_or_default_profile',
                    ],
                    'auth' => [
                        'type' => 'token_or_mtls_or_signed_headers_or_none',
                        'source' => 'one_of_source_values',
                        'value' => 'redacted_or_reference_only',
                    ],
                    'tls' => [
                        'verify' => 'boolean',
                        'source' => 'one_of_source_values',
                    ],
                    'identity' => [
                        'subject' => 'nullable_string',
                        'roles' => 'list_of_strings',
                        'source' => 'server_or_carrier',
                    ],
                ],
            ],
            'redaction' => [
                'never_echo' => [
                    'bearer_tokens',
                    'private_keys',
                    'shared_signature_keys',
                    'client_certificate_private_key_material',
                    'raw_authorization_headers',
                ],
                'allowed_diagnostics' => [
                    'redacted',
                    'secret_reference_name',
                    'environment_variable_name',
                    'profile_name',
                    'certificate_reference_name',
                    'key_reference_name',
                ],
            ],
            'resolution' => [
                'server_url' => 'carriers normalize and use the winning absolute URL without appending hidden path defaults',
                'namespace' => 'carriers send the winning namespace explicitly when the protocol supports namespace headers',
                'identity' => 'servers may report authenticated subject and roles; carriers must treat identity as server-asserted when present',
                'tls' => 'carriers must fail closed on invalid TLS unless the winning tls.verify value is false',
            ],
        ];
    }
}
