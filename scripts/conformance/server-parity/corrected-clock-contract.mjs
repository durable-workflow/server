import assert from 'node:assert/strict';
import {gunzipSync, inflateSync, brotliDecompressSync} from 'node:zlib';

const typed = value => value === null ? {type: 'null', value: null}
  : typeof value === 'boolean' ? {type: 'boolean', value} : {type: 'int64', value: String(value)};

export function checkCorrectedClock(fixture, observation, workflowId) {
  const spec = fixture.corrected_markers, clock = spec.python_clock;
  assert(clock.applicable_modes.includes(observation.mode), 'actual SDK clock mode; embedded recorded UUID semantics remain separate');
  assert.equal(observation.workflow_id, workflowId);
  assert.equal(observation.workflow_type, fixture.workflow_type);
  assert.equal(observation.namespace, 'default');
  assert.equal(observation.task_queue, workflowId+'-workflow');
  assert.equal(observation.status, 'completed');
  assert.equal(observation.payload_codec, 'avro');
  assert.deepEqual(observation.typed_input, {type:'list', value:[fixture.typed_value]});
  const output = {type:'map',value:{projection:{type:'list',value:spec.expected_decisions.map(typed)},typed:fixture.typed_value}};
  assert.deepEqual(observation.typed_output, output);
  const {phases, requests} = observation.corrected_markers;
  assert.deepEqual(Object.keys(phases), clock.phase_order);
  assert.equal(observation.corrected_markers.phase_budget_seconds, 30);
  assert.equal(observation.corrected_markers.overall_budget_seconds, 150);
  const all = observation.events;
  const expectedEvents = ['StartAccepted','WorkflowStarted',...spec.markers.map(()=> 'VersionMarkerRecorded'),
    'ActivityScheduled','ActivityStarted','ActivityCompleted','ActivityScheduled','ActivityStarted','ActivityCompleted','WorkflowCompleted'];
  assert.deepEqual(all.map(row=>row.event_type), expectedEvents);
  for (const [index,event] of all.entries()) {
    assert.equal(event.sequence, index+1);
    assert.match(event.timestamp,/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/);
  }
  const pids = [];
  for (const [name,phase] of Object.entries(phases)) {
    const actor = phase.actor;
    assert.equal(actor.workflow_id,workflowId); assert.equal(actor.run_id,observation.run_id);
    assert(Number.isSafeInteger(actor.pid) && actor.pid>0); pids.push(actor.pid);
    assert.equal(actor.worker_finished,true);
    assert.deepEqual(all.slice(0,phase.events.length),phase.events,'whole immutable original prefix');
    for (const key of ['execution_deadline_at','run_deadline_at']) assert.equal(phase.execution[key],phases.original.execution[key]);
    assert.equal(phase.status,name==='replacement'?'completed':'waiting');
    assert.equal(actor.mode,['activity','second_activity'].includes(name)?'activity':'workflow');
    assert.equal(actor.case,spec.case); assert.equal(actor.retry_policy,'empty');
    assert.equal(actor.range_profile,name==='replacement'?'wider':name==='clock_wait'?'clock-wait':'original');
  }
  assert.equal(new Set(pids).size,pids.length,'five actual independent actor processes');
  assert.deepEqual(phases.original.actor.decisions,[spec.original_expected_decisions ?? spec.expected_decisions]);
  assert.deepEqual(phases.activity.actor.decisions,[]); assert.deepEqual(phases.second_activity.actor.decisions,[]);
  const markers = all.filter(row=>row.event_type==='VersionMarkerRecorded');
  assert.equal(markers.length,spec.markers.length);
  for (const [index,marker] of markers.entries()) {
    const {task,...fields}=marker.payload;
    assert.deepEqual(fields,{change_id:spec.change_id,...spec.markers[index]});
    assert.equal(marker.sequence,index+3);
    assert.equal(task.attempt_count,1); assert.equal(task.repair_count,0);
  }
  const scheduled = all.filter(row=>row.event_type==='ActivityScheduled');
  const completed = all.filter(row=>row.event_type==='ActivityCompleted');
  assert.equal(phases.original.events.length,3+markers.length);
  assert.equal(phases.activity.events.length,5+markers.length);
  assert.equal(phases.clock_wait.events.length,6+markers.length);
  assert.equal(phases.second_activity.events.length,8+markers.length);
  assert.notEqual(scheduled[0].payload.activity_execution_id,scheduled[1].payload.activity_execution_id);
  for (const [index,event] of scheduled.entries()) {
    assert.equal(event.payload.sequence,markers.length+index+1);
    assert.equal(event.payload.activity.queue,workflowId+'-activity');
    assert.deepEqual(event.payload.activity.retry_policy,{snapshot_version:1,max_attempts:1,backoff_seconds:[],
      start_to_close_timeout:120,schedule_to_start_timeout:null,schedule_to_close_timeout:600,
      heartbeat_timeout:null,non_retryable_error_types:[]});
    assert.deepEqual(event.typed_decoded.activity_arguments,{type:'list',value:[fixture.typed_value]});
    assert.equal(completed[index].payload.activity_execution_id,event.payload.activity_execution_id);
    assert.deepEqual(completed[index].typed_decoded.result,fixture.typed_value);
  }
  const first = phases.clock_wait.actor.clock_reads, second = phases.replacement.actor.clock_reads;
  assert.equal(first.length,1); assert.equal(second.length,1);
  assert.equal(clock.clock_contract, 'fixed_workflow_start');
  assert.equal(clock.contract_receipt, 'https://github.com/durable-workflow/sdk-python/issues/146#issuecomment-6139915817');
  const expectedClocks = Array(clock.marker_clock_positions.length+2).fill(all[1].timestamp);
  assert.deepEqual(first[0].decisions,spec.expected_decisions);
  assert.deepEqual(second[0].decisions,spec.expected_decisions);
  assert.deepEqual(first[0].clocks,expectedClocks,'public Python clock uses its agreed original workflow-start anchor');
  assert.deepEqual(second[0].clocks,[...expectedClocks,all[1].timestamp]);
  assert.equal(first[0].uuids.length,expectedClocks.length);
  assert.equal(second[0].uuids.length,expectedClocks.length+1);
  assert.deepEqual(second[0].uuids.slice(0,first[0].uuids.length),first[0].uuids,'same-original UUID prefix across actual cold workers');
  for (const snapshot of [first[0],second[0]]) for (const [index,uuid] of snapshot.uuids.entries()) {
    assert.match(uuid,/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    assert.equal(parseInt(uuid.replaceAll('-','').slice(0,12),16),Date.parse(snapshot.clocks[index]),'public UUIDv7 original clock millisecond');
  }
  assert.equal(phases.clock_wait.actor.shutdown,'public_stop'); assert.equal(phases.replacement.actor.shutdown,'public_stop');
  const wire = requests.map(row=>{
    assert.equal(row.transport_error,null);
    const request = row.request_body?JSON.parse(row.request_body):null;
    if (row.client_cancelled) {
      assert.equal(row.method,'POST'); assert.equal(row.status,0); assert.equal(row.response_body,'');
      assert.equal(row.response_bytes_base64,''); assert.equal(row.request_body_complete,true); assert.equal(row.upstream_request_started,true);
      assert(['/api/worker/workflow-tasks/poll','/api/worker/activity-tasks/poll','/api/worker/query-tasks/poll'].includes(row.path));
      const current = [workflowId+':workflow:clock-wait',workflowId+':workflow:wider'];
      if (!current.includes(request.worker_id)) {
        assert.equal(spec.producer,'python'); assert.equal(phases.original.actor.author_exit_code,-9);
        assert.equal(request.worker_id,workflowId+':workflow:original');
      }
      return {...row,request,response:null};
    }
    const decode={identity:x=>x,gzip:gunzipSync,deflate:inflateSync,br:brotliDecompressSync}[row.response_encoding];
    assert(decode); assert.equal(decode(Buffer.from(row.response_bytes_base64,'base64')).toString('utf8'),row.response_body);
    return {...row,request,response:row.response_body?JSON.parse(row.response_body):null};
  });
  const deliveries = wire.filter(row=>row.path==='/api/worker/workflow-tasks/poll'&&row.response?.task);
  assert.equal(deliveries.length,3);
  for (const [index,count] of [2,phases.activity.events.length,phases.second_activity.events.length].entries()) {
    const task=deliveries[index].response.task;
    assert.equal(task.workflow_id,workflowId); assert.equal(task.run_id,observation.run_id);
    assert.equal(task.sticky_replay_mode,'cold_replay'); assert.equal(task.next_history_page_token,null);
    assert.equal(task.total_history_events,count);
    assert.deepEqual(task.history_events.map(row=>({sequence:row.sequence,event_type:row.event_type,timestamp:row.recorded_at,payload:row.payload})),
      all.slice(0,count).map(({sequence,event_type,timestamp,payload})=>({sequence,event_type,timestamp,payload})));
  }
  const outcomes=wire.filter(row=>row.method==='POST'&&/^\/api\/worker\/workflow-tasks\/[^/]+\/complete$/.test(row.path));
  assert.equal(outcomes.length,3);
  for (const row of outcomes) {assert.equal(row.status,200);assert.equal(row.response.recorded,true);}
  const activityOutcomes=wire.filter(row=>row.method==='POST'&&/^\/api\/worker\/activity-tasks\/[^/]+\/complete$/.test(row.path));
  assert.equal(activityOutcomes.length,2);
  for (const row of activityOutcomes) {assert.equal(row.status,200);assert.equal(row.response.recorded,true);}
  assert(!wire.some(row=>row.path.endsWith('/repair')));
  assert(!wire.some(row=>row.path.endsWith('/fail')));
  const finalTask=all.at(-1).payload.task; assert.equal(finalTask.attempt_count,1); assert.equal(finalTask.repair_count,0);
  return {fixture_id:fixture.id,workflow_id:workflowId,run_id:'@run:1',status:'completed',output,
    markers:spec.markers,events:expectedEvents,activity_count:2,clock_contract:clock.clock_contract,clock_reads:expectedClocks.length,
    uuid7_same_original_prefix:true,cold_processes:5};
}
