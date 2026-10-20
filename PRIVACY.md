# MissionBayXrm: Privacy and Data Processing

This document describes processing visible in the shipped MissionBayXrm source. It is a technical inventory, not a deployment privacy notice or a claim of legal compliance. The host administrator is responsible for the actual purpose, legal basis, recipient agreements, retention and erasure policy.

## Processing and storage

The `XrmEmbeddingEnqueueJob` and `XrmEmbeddingQueueExtractorAgentResource` read XRM entry data and process entry identifiers, content metadata and potentially personal or confidential text. The queue extractor uses the existing XRM tables `base3_embedding_job`, `base3_embedding_seen` and `base3system_sysentry`; corresponding job updates can store state, attempts, error messages, timestamps and source identifiers. The enqueue job also uses the host's `IStateStore` for scheduling markers. The job/seen tables and any `IStateStore` backend are owned by the host installation, not created by this compatibility fix.

When enabled in the configured embedding flow, parsed and chunked entry content can be submitted to an embedding-model service and stored in a retrieval/vector backend such as Qdrant. Actual provider endpoint, physical storage, geographic location, access controls, logging, retention, deletion, and transfers depend on the deployment. Domain parser resources may place XRM entry metadata into retrieval items. Their contents can include user-entered data, customer/contact attributes and content identifiers; operators must review the configured data sources and exported payload fields.

## Compatibility fix

`MissionBay\\Api\\IAgentRagPayloadNormalizer` and its `AgentEmbeddingChunk`-based implementation no longer exist in the installed MissionBay contract. The unused XRM implementation and its DI registration have been removed. Payload construction now belongs to the host-configured `AssistantFoundation\\Api\\IRetrievalCollectionDefinition`. This fix does not introduce a new database, read additional personal attributes, change retention policy, export data, or modify stored records. It does not itself change which collection definition the host uses. XRM-specific payload fields are not guaranteed by the generic default definition; operators must verify the intended indexing configuration before resuming a production embedding flow.

## Access, retention, deletion and known limits

The XRM database and embedding backend remain the data owners for access, update and deletion. The shipped enqueue and extractor code contains job-state updates and delete handling, but no globally applicable retention schedule for queue rows, source content, vector points or backups. Archive/deletion propagation depends on the configured worker/flow and successful backend operations. Restrict database, vector-store, provider and job-log access using host-side controls; do not assume payload contents are automatically anonymized. No additional network recipient is introduced by this compatibility fix.

There is no newly introduced administration UI in this change. Existing XRM UI and job operations retain their host-specific authorization and accessibility obligations; these are not re-certified by this small startup fix. For the shared runtime's processing, see `plugin/MissionBay/PRIVACY.md` in the host installation.
