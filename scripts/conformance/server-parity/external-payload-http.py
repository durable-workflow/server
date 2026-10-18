"""Bounded byte-transport checks against a fresh task-owned namespace runtime."""
import hashlib
import json
import sys
import urllib.error
import urllib.request

url = sys.argv[1].rstrip('/')
token = 'external-fixture-token'
checks = []

def request(method, path, data=None, metadata=None, namespace='default', auth=token, headers=None):
    fields = {'Authorization': 'Bearer '+auth, 'X-Namespace': namespace}
    if headers:
        fields.update(headers)
    if metadata is not None:
        fields.update({'X-Durable-Workflow-Payload-Codec': 'avro',
            'X-Durable-Workflow-Payload-Size': str(metadata['size_bytes']),
            'X-Durable-Workflow-Payload-SHA256': metadata['sha256']})
    req = urllib.request.Request(url+path, data=data, headers=fields, method=method)
    try:
        response = urllib.request.urlopen(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.headers, response.read(5 * 1024 * 1024)

def expect(condition, label):
    if not condition:
        raise AssertionError(label)
    checks.append(label)

def policy(namespace):
    data = json.dumps({'driver':'local','enabled':True,'threshold_bytes':16,
        'config':{'uri':'file:///state/objects'}}).encode()
    status, _, body = request('PUT', '/api/namespaces/'+namespace+'/external-storage', data,
        headers={'Content-Type':'application/json','X-Durable-Workflow-Control-Plane-Version':'2'})
    expect(status == 200, 'local_policy_'+namespace+'_'+str(status))

policy('default')
data = b'x' * (3 * 1024 * 1024 + 1)
metadata = {'size_bytes':len(data),'sha256':hashlib.sha256(data).hexdigest()}
status, headers, body = request('POST','/api/external-payloads/v1',data,metadata,
    headers={'Content-Type':'application/octet-stream'})
expect(status == 201, 'upload_over_json_limit_'+str(status))
upload = json.loads(body)
expect(upload['transport_version'] == 1, 'integer_transport_version')
reference = upload['reference']
expect(set(reference) == {'schema','reference_id','codec','size_bytes','sha256'}, 'opaque_five_field_reference')
expect(reference['size_bytes'] == len(data) and reference['sha256'] == metadata['sha256'], 'exact_upload_metadata')
status, _, body = request('POST','/api/external-payloads/v1',data,metadata,
    headers={'Content-Type':'application/octet-stream'})
expect(status == 201 and json.loads(body)['reference'] == reference, 'retry_same_reference')
path = '/api/external-payloads/v1/'+reference['reference_id']
status, headers, body = request('GET',path,metadata=reference)
expect(status == 200 and body == data, 'fetched_exact_encoded_bytes')
expect(headers['Content-Type'].split(';')[0] == 'application/octet-stream', 'octet_stream_response')
expect(headers['X-Durable-Workflow-Payload-SHA256'] == metadata['sha256'], 'verified_response_digest')
expect(headers['ETag'] == '"sha256:'+metadata['sha256']+'"', 'content_etag')
expect('private' in headers['Cache-Control'] and 'immutable' in headers['Cache-Control'], 'private_immutable_cache')
status, _, body = request('GET',path,metadata=reference,auth='wrong')
expect(status == 401 and json.loads(body)['reason'] == 'external_payload_unauthorized', 'authentication_precedes_reference')
wrong = dict(reference, sha256='0'*64)
status, _, body = request('GET',path,metadata=wrong)
expect(status == 422 and json.loads(body)['reason'] == 'external_payload_integrity_mismatch', 'conflicting_metadata_refused')
small = b'new'
small_meta = {'size_bytes':len(small),'sha256':hashlib.sha256(small).hexdigest()}
status, headers, body = request('POST','/api/external-payloads/v1',small,small_meta,
    headers={'Content-Type':'application/octet-stream'})
expect(status == 429 and json.loads(body)['reason'] == 'external_payload_namespace_objects_exhausted', 'object_quota_refusal')
expect(headers['Retry-After'] == '60' and json.loads(body)['retryable'] is True, 'quota_retry_disposition')
status, _, body = request('POST','/api/namespaces',json.dumps({'name':'other'}).encode(),
    headers={'Content-Type':'application/json','X-Durable-Workflow-Control-Plane-Version':'2'})
expect(status == 201, 'separate_namespace_admission_'+str(status))
policy('other')
status, _, body = request('GET',path,metadata=reference,namespace='other')
expect(status == 404 and json.loads(body)['reason'] == 'external_payload_not_found', 'cross_namespace_reference_refusal')
status, _, body = request('POST','/api/external-payloads/v1',data,metadata,namespace='other',
    headers={'Content-Type':'application/octet-stream'})
expect(status == 201 and json.loads(body)['reference']['reference_id'] != reference['reference_id'], 'namespace_specific_identity')
print(json.dumps({'outcome':'pass','scope':'actual_http_bytes_only','encoded_bytes':len(data),
    'sha256':metadata['sha256'],'checks':checks}))
