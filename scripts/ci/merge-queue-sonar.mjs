#!/usr/bin/env node

import {execFileSync} from 'node:child_process';
import {appendFileSync, copyFileSync, lstatSync, readFileSync, readdirSync} from 'node:fs';
import {resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

export function queueIdentity(event, repositoryId, repositoryName) {
  const run = event.workflow_run;
  if (!/^[1-9]\d*$/.test(String(repositoryId)) || repositoryName !== 'durable-workflow/server' ||
      event.repository?.id !== Number(repositoryId) || event.repository?.full_name !== repositoryName ||
      run?.repository?.id !== Number(repositoryId) || run?.head_repository?.id !== Number(repositoryId) ||
      run?.name !== 'PHPUnit Feature' || run?.path !== '.github/workflows/phpunit-feature.yml' ||
      run?.event !== 'merge_group' || run?.status !== 'completed' || run?.conclusion !== 'success') {
    throw new Error('Expected a successful merge_group PHPUnit Feature run from this repository');
  }
  if (typeof run.head_sha !== 'string' || !/^[0-9a-f]{40}$/.test(run.head_sha) || /^0+$/.test(run.head_sha) ||
      typeof run.head_branch !== 'string' || !/^gh-readonly-queue\/main\/pr-[1-9]\d*-[0-9a-f]{40}$/.test(run.head_branch)) {
    throw new Error('Invalid merge queue commit or branch identity');
  }
  return {sha: run.head_sha, branch: run.head_branch};
}

function regularSource(directory) {
  for (const entry of readdirSync(directory, {withFileTypes: true})) {
    if (entry.name === '.git') continue;
    const path = resolve(directory, entry.name);
    if (entry.isDirectory()) regularSource(path);
    else if (!entry.isFile()) throw new Error('Analysis data must contain only regular files and directories');
  }
}

export function prepareSource(candidateDirectory, trustedConfiguration, sha) {
  const candidate = resolve(candidateDirectory);
  if (!lstatSync(candidate).isDirectory()) throw new Error('Candidate must be a directory');
  const head = execFileSync('/usr/bin/git', ['-C', candidate, 'rev-parse', 'HEAD'], {encoding: 'utf8'}).trim();
  if (head !== sha) throw new Error('Checked-out candidate differs from the merge queue SHA');
  const entries = execFileSync('/usr/bin/git', ['-C', candidate, 'ls-files', '--stage', '-z'], {encoding: 'utf8'}).split('\0');
  if (entries.some(entry => entry.startsWith('120000 ') || entry.startsWith('160000 '))) {
    throw new Error('Merge queue analysis does not follow symlinks or submodules');
  }
  regularSource(candidate);
  // Candidate scanner properties never control the credentialed analysis.
  copyFileSync(trustedConfiguration, resolve(candidate, 'sonar-project.properties'));
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const event = JSON.parse(readFileSync(process.env.GITHUB_EVENT_PATH, 'utf8'));
  const identity = queueIdentity(event, process.env.GITHUB_REPOSITORY_ID, process.env.GITHUB_REPOSITORY);
  if (process.argv[2] === 'validate') {
    appendFileSync(process.env.GITHUB_OUTPUT, `sha=${identity.sha}\nbranch=${identity.branch}\n`);
  } else if (process.argv[2] === 'prepare') {
    prepareSource('.sonar-candidate', '.sonar-control/sonar-project.properties', identity.sha);
  } else {
    throw new Error('Expected validate or prepare');
  }
}
