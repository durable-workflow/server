// Mutation controls use actual normal-worker records, never generated history.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {checkObservation} from '../contract.mjs';
import {checkCorrectedMarkerPackages} from '../corrected-marker-contract.mjs';

const record=JSON.parse(readFileSync(process.argv[2]));
const results=[];
for(const item of record.cases){
  const original=item.observation, fixture=item.fixture;
  const read=value=>{checkCorrectedMarkerPackages(fixture,value,record.artifacts);return checkObservation(fixture,value,original.workflow_id);};
  read(original);
  const phases=value=>value.corrected_markers.phases;
  const mutations={
    'wall-clock fallback':value=>{phases(value).clock_wait.actor.clock_reads[0].clocks[0]='2000-01-01T00:00:00.000000Z';},
    'unagreed event-clock advancement':value=>{phases(value).replacement.actor.clock_reads[0].clocks[1]=value.events[2].timestamp;},
    'cold UUID prefix changed':value=>{const read=phases(value).replacement.actor.clock_reads[0];read.uuids[0]=read.uuids.at(-1);},
    'UUIDv7 version changed':value=>{const read=phases(value).clock_wait.actor.clock_reads[0];read.uuids[0]=read.uuids[0].slice(0,14)+'4'+read.uuids[0].slice(15);},
    'UUIDv7 clock changed':value=>{const read=phases(value).clock_wait.actor.clock_reads[0];read.uuids[0]='00000000-0000'+read.uuids[0].slice(13);},
    'genuine marker microclock changed':value=>{value.events[2].timestamp='2000-01-01T00:00:00.000000Z';},
    'stored marker bound removed':value=>{delete value.events[2].payload.min_supported;},
    'original history prefix changed':value=>{phases(value).original.events[2].payload.change_id='other';},
    'second activity identity reused':value=>{const scheduled=value.events.filter(row=>row.event_type==='ActivityScheduled');scheduled[1].payload.activity_execution_id=scheduled[0].payload.activity_execution_id;},
    'missing second activity completion':value=>{value.events.splice(value.events.findLastIndex(row=>row.event_type==='ActivityCompleted'),1);},
    'typed integer rounded':value=>{value.typed_output.value.typed.value.integer.value='9007199254740992';},
    'typed null lost':value=>{value.typed_output.value.typed.value.optional={type:'string',value:''};},
    'cold process reused':value=>{phases(value).replacement.actor.pid=phases(value).clock_wait.actor.pid;},
    'wrong original successor':value=>{phases(value).replacement.actor.run_id='other';},
    'run deadline rebased':value=>{phases(value).replacement.execution.run_deadline_at='2000-01-01T00:00:00Z';},
    'observation deadline extended':value=>{value.corrected_markers.overall_budget_seconds=180;},
    'false terminal outcome':value=>{value.status='waiting';},
    'loaded current SDK bytes changed':value=>{phases(value).clock_wait.actor.loaded_sources['workflow.py']='0'.repeat(64);},
    'actual response bytes omitted':value=>{value.corrected_markers.requests.find(row=>row.status===200).response_bytes_base64='';},
    'cold public delivery omitted':value=>{value.corrected_markers.requests=value.corrected_markers.requests.filter(row=>row.path!=='/api/worker/workflow-tasks/poll');},
    'committed completion cancelled':value=>{value.corrected_markers.requests.find(row=>row.path.endsWith('/complete')).client_cancelled=true;},
  };
  for(const[name,mutate]of Object.entries(mutations)){
    const corrupted=structuredClone(original);mutate(corrupted);assert.throws(()=>read(corrupted),undefined,name);
  }
  results.push({fixture_id:fixture.id,actual_record:true,mutation_controls:Object.keys(mutations)});
}
console.log(JSON.stringify({outcome:'pass',results}));
