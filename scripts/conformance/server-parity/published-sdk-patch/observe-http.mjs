// Forward unchanged SDK HTTP bytes; retain bodies/statuses, never credentials.
import http from 'node:http';
import {appendFileSync} from 'node:fs';
import {gunzipSync, inflateSync, brotliDecompressSync} from 'node:zlib';

const [target, journal] = process.argv.slice(2);
const upstream = new URL(target);
if (upstream.protocol !== 'http:') throw new Error('Isolated fixture target must use HTTP');
const server = http.createServer((request, response) => {
  const chunks = [];
  let forwarded;
  let recorded = false;
  const record = fields => {
    if (recorded) return;
    recorded = true;
    appendFileSync(journal, JSON.stringify({method: request.method, path: new URL(request.url, upstream).pathname,
      request_body: Buffer.concat(chunks).toString('utf8'), status: 0, response_encoding: 'identity',
      response_retry_after: null, response_bytes_base64: '', response_body: '', client_cancelled: false,
      transport_error: null, ...fields})+'\n');
  };
  const failed = error => {
    record({transport_error: error.code ?? 'upstream_error'});
    response.destroy(error);
  };
  response.on('close', () => {
    if (!recorded && !response.writableFinished) {
      record({client_cancelled: true});
      forwarded?.destroy();
    }
  });
  request.on('data', chunk => chunks.push(chunk));
  request.on('end', () => {
    if (recorded) return;
    const body = Buffer.concat(chunks);
    forwarded = http.request(new URL(request.url, upstream), {
      method: request.method,
      headers: {...request.headers, host: upstream.host},
    }, incoming => {
      const received = [];
      incoming.on('data', chunk => received.push(chunk));
      incoming.on('end', () => {
        if (recorded) return;
        const bytes = Buffer.concat(received);
        const encoding = incoming.headers['content-encoding'] ?? 'identity';
        const decoders = {identity: value => value, gzip: gunzipSync, deflate: inflateSync, br: brotliDecompressSync};
        if (!decoders[encoding]) throw new Error(`Unsupported observed content encoding: ${encoding}`);
        record({status: incoming.statusCode, response_encoding: encoding,
          response_retry_after: incoming.headers['retry-after'] ?? null,
          response_bytes_base64: bytes.toString('base64'), response_body: decoders[encoding](bytes).toString('utf8')});
        response.writeHead(incoming.statusCode, incoming.headers);
        response.end(bytes);
      });
      incoming.on('error', failed);
    });
    forwarded.on('error', failed);
    forwarded.end(body);
  });
});
server.listen(0, '127.0.0.1', () => console.log(`http://127.0.0.1:${server.address().port}`));
