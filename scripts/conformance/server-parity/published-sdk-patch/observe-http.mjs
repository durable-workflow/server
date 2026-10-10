// Forward unchanged SDK HTTP bytes; retain bodies/statuses, never credentials.
import http from 'node:http';
import {appendFileSync} from 'node:fs';
import {gunzipSync, inflateSync, brotliDecompressSync} from 'node:zlib';

const [target, journal] = process.argv.slice(2);
const upstream = new URL(target);
if (upstream.protocol !== 'http:') throw new Error('Isolated fixture target must use HTTP');
const server = http.createServer((request, response) => {
  const chunks = [];
  request.on('data', chunk => chunks.push(chunk));
  request.on('end', () => {
    const body = Buffer.concat(chunks);
    const forwarded = http.request(new URL(request.url, upstream), {
      method: request.method,
      headers: {...request.headers, host: upstream.host},
    }, incoming => {
      const received = [];
      incoming.on('data', chunk => received.push(chunk));
      incoming.on('end', () => {
        const bytes = Buffer.concat(received);
        const encoding = incoming.headers['content-encoding'] ?? 'identity';
        const decoders = {identity: value => value, gzip: gunzipSync, deflate: inflateSync, br: brotliDecompressSync};
        if (!decoders[encoding]) throw new Error(`Unsupported observed content encoding: ${encoding}`);
        appendFileSync(journal, JSON.stringify({method: request.method, path: new URL(request.url, upstream).pathname,
          request_body: body.toString('utf8'), status: incoming.statusCode, response_encoding: encoding,
          response_bytes_base64: bytes.toString('base64'), response_body: decoders[encoding](bytes).toString('utf8')})+'\n');
        response.writeHead(incoming.statusCode, incoming.headers);
        response.end(bytes);
      });
      incoming.on('error', error => response.destroy(error));
    });
    forwarded.on('error', error => response.destroy(error));
    forwarded.end(body);
  });
});
server.listen(0, '127.0.0.1', () => console.log(`http://127.0.0.1:${server.address().port}`));
