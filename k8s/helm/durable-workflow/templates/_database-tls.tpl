{{- define "durable-workflow.databaseTlsCaPath" -}}
/etc/durable-workflow/database-tls/ca.pem
{{- end -}}

{{- define "durable-workflow.validateDatabaseTlsVolumes" -}}
{{- if .Values.externalDatabase.tls.existingSecret -}}
{{- range $role := list .Values.server .Values.worker -}}
{{- range $volume := $role.extraVolumes -}}
{{- if eq $volume.name "database-tls" -}}
{{- fail "The database-tls volume name is reserved for database TLS." -}}
{{- end -}}
{{- end -}}
{{- range $mount := $role.extraVolumeMounts -}}
{{- if or (eq $mount.name "database-tls") (eq $mount.mountPath "/etc/durable-workflow/database-tls") -}}
{{- fail "The database-tls volume name and mount path are reserved for database TLS." -}}
{{- end -}}
{{- end -}}
{{- end -}}
{{- end -}}
{{- end -}}

{{- define "durable-workflow.databaseTlsVolumeMount" -}}
{{- if .Values.externalDatabase.tls.existingSecret -}}
- name: database-tls
  mountPath: /etc/durable-workflow/database-tls
  readOnly: true
{{- end -}}
{{- end -}}

{{- define "durable-workflow.databaseTlsVolume" -}}
{{- if .Values.externalDatabase.tls.existingSecret -}}
- name: database-tls
  secret:
    secretName: {{ .Values.externalDatabase.tls.existingSecret | quote }}
    items:
      - key: {{ .Values.externalDatabase.tls.caKey | quote }}
        path: ca.pem
{{- end -}}
{{- end -}}
