{{/* A read-only PHP container uses writable volumes for its runtime files.
Explicit mounts at the same paths take precedence over the chart's emptyDirs. */}}
{{- define "durable-workflow.phpReadOnlyMounts" -}}
{{- $mounts := list -}}
{{- if .readOnly -}}
  {{- $existing := dict -}}
  {{- range .extraMounts -}}
    {{- $_ := set $existing .mountPath true -}}
  {{- end -}}
  {{- if not (hasKey $existing "/tmp") -}}
    {{- $mounts = append $mounts (dict "name" "dw-php-tmp" "mountPath" "/tmp") -}}
  {{- end -}}
  {{- if and .apache (not (hasKey $existing "/var/run/apache2")) -}}
    {{- $mounts = append $mounts (dict "name" "dw-php-apache-run" "mountPath" "/var/run/apache2") -}}
  {{- end -}}
{{- end -}}
{{- toYaml $mounts -}}
{{- end -}}

{{- define "durable-workflow.phpReadOnlyVolumes" -}}
{{- range . }}
- name: {{ .name }}
  emptyDir: {}
{{- end -}}
{{- end -}}

{{/* Preserve the image's baked route and package caches. Laravel can write
its environment-specific config cache under the same /tmp used at startup. */}}
{{- define "durable-workflow.phpReadOnlyCacheEnv" -}}
{{- $custom := hasKey .config "APP_CONFIG_CACHE" -}}
{{- range .extraEnv -}}
  {{- if eq .name "APP_CONFIG_CACHE" -}}
    {{- $custom = true -}}
  {{- end -}}
{{- end -}}
{{- if and .readOnly (not $custom) }}
- name: APP_CONFIG_CACHE
  value: /tmp/durable-workflow-config.php
{{- end -}}
{{- end -}}
