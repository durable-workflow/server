# October 22, principal attribution contract

- **Found by.** The third disposable VM run of the Sample App against
  native (`dw-rs325-20261022T032200`). The namespaces scenario's setup reads
  `principal_attribution_contract` from `/api/cluster/info`, and fails with
  `KeyError` on native. The setup compares that contract's spoofing guards
  with the forged fields and headers its gateway injects.
- **Source.** Native's cluster information now carries published PHP's
  `PrincipalAttributionContract::manifest()` verbatim, bundled as
  `principal-attribution-contract.v2.json`. The manifest is byte-identical
  in Server 2.5.14, 2.10.0 and 2.11.0. Native records the authenticated
  principal as the contract describes (the command context and query
  principal checkpoints).
- **Scope.** Published PHP's cluster information carries about forty contract
  manifests. Native publishes only the ones it honors: the auth composition
  contract and now this one. The Sample App reads no other manifest.
- **Verification.**
  - A new integration test checks the manifest's identity, the anonymous
    principal value, and the forged body values and header list the gateway
    compares.
  - The existing cluster information tests pass.
