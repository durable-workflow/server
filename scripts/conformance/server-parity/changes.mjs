#!/usr/bin/env node
// Decides whether Shared Server fixtures must run for this event.
//
// Pull requests run the workflow without a path filter, so its terminal
// result always reports. This script applies the workflow's own push path
// filter to the pull request's changes, so one list governs both triggers.
// Every other event runs every fixture job, and so does any pull request
// whose changes cannot be classified.
//
// Writes `run=true` or `run=false` to stdout, for $GITHUB_OUTPUT.
import {execFileSync} from 'node:child_process';
import {readFileSync} from 'node:fs';
import {pathToFileURL} from 'node:url';

// The `paths` list of the workflow's `on.push` trigger.
export function pushPathPatterns(workflowText) {
  const lines = workflowText.split('\n');
  const on = lines.indexOf('on:');
  const push = lines.indexOf('  push:', on);
  if (on < 0 || push < 0) throw new Error('The workflow has no on.push trigger');
  const patterns = [];
  let inPaths = false;
  for (const line of lines.slice(push + 1)) {
    if (/^ {0,3}\S/.test(line)) break;
    if (line === '    paths:') {
      inPaths = true;
    } else if (inPaths && line.startsWith('      - ')) {
      patterns.push(line.slice('      - '.length).trim());
    } else if (inPaths) {
      break;
    }
  }
  if (patterns.length === 0) throw new Error('The push trigger has no path filter');
  return patterns;
}

// GitHub's filter syntax, limited to the forms this workflow uses:
// `**` matches any characters, `*` matches any characters except `/`. Every
// other character is literal, and file names may hold any character but NUL,
// newlines included.
export function patternRegExp(pattern) {
  if (!/^[A-Za-z0-9._/*-]+$/.test(pattern)) throw new Error(`Unsupported path filter pattern: ${pattern}`);
  const literal = text => text.replace(/[\\^$.*+?()[\]{}|\/-]/g, '\\$&');
  const body = pattern.split('**').map(part => part.split('*').map(literal).join('[^/]*')).join('[^]*');
  return new RegExp(`^${body}$`);
}

// `git diff --name-only -z` output: complete paths split only on NUL, never
// quoted. A path that is not UTF-8 cannot be classified.
export function nulSeparatedPaths(bytes) {
  const decoder = new TextDecoder('utf-8', {fatal: true});
  const paths = [];
  let start = 0;
  for (let index = bytes.indexOf(0); index >= 0; index = bytes.indexOf(0, start)) {
    paths.push(decoder.decode(bytes.subarray(start, index)));
    start = index + 1;
  }
  if (start !== bytes.length) throw new Error('The file list does not end with NUL');
  return paths;
}

export function matchingFiles(patterns, files) {
  const expressions = patterns.map(patternRegExp);
  return files.filter(file => expressions.some(expression => expression.test(file)));
}

function classify({event, headSha, workflowPath}) {
  if (event !== 'pull_request') return {run: true, reason: `${event} runs every fixture job`};
  // The checkout is the pull request's merge commit: base, then head. Git runs
  // without a shell, and its file list is read as bytes.
  const git = (...args) => execFileSync('git', args);
  let files;
  let matched;
  try {
    const parents = git('rev-list', '--parents', '-n', '1', 'HEAD').toString('utf8').trim().split(' ');
    if (parents.length !== 3 || parents[2] !== headSha) throw new Error('HEAD is not this pull request\'s merge commit');
    files = nulSeparatedPaths(git('diff', '--name-only', '-z', '--no-renames', 'HEAD^1', 'HEAD'));
    matched = matchingFiles(pushPathPatterns(readFileSync(workflowPath, 'utf8')), files);
  } catch (error) {
    return {run: true, reason: `changes could not be classified (${error.message}); running every fixture job`};
  }
  return matched.length > 0
    ? {run: true, reason: `fixture inputs changed: ${matched.map(file => JSON.stringify(file)).join(', ')}`}
    : {run: false, reason: `no fixture input among ${files.length} changed files`};
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const decision = classify({
    event: process.env.EVENT_NAME ?? '',
    headSha: process.env.PR_HEAD_SHA ?? '',
    workflowPath: '.github/workflows/server-parity.yml',
  });
  console.error(decision.reason);
  console.log(`run=${decision.run}`);
}
