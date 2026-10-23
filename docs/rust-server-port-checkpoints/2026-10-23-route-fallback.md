# October 23, unknown routes and methods answer like PHP

- **The gap.** Native answered every request it had no route for with
  `501 rust_capability_not_implemented`, including paths published Server
  2.11.1 does not serve at all. A known path with the wrong method got an
  empty 405. PHP answers:
  - `404 not_found`: `The route {path} could not be found.`
  - `405 method_not_allowed`: `The {METHOD} method is not supported for
    route {path}. Supported methods: …`

  Worker-plane answers also carry `protocol_version` and
  `server_capabilities`.
- **What native does now.** Native embeds published 2.11.1's route table
  (`rust/src/runtime/php-routes.v2.11.1.json`, from `php artisan
  route:list`). A request no native route serves is matched against it:
  - a path PHP serves with this method is still `501
    rust_capability_not_implemented`: PHP has the capability and native
    does not yet;
  - a path PHP serves with other methods gets PHP's 405, listing those
    methods;
  - any other path gets PHP's 404.

  The deployment name is the one path parameter that spans segments, as
  its `->where('name', '.+')` does.
- **How it was checked.** A probe sent seven requests to native and the
  published image: unknown control-plane, run and worker paths, two wrong
  methods, and the root-level unknown path. Bodies and statuses matched
  exactly.
