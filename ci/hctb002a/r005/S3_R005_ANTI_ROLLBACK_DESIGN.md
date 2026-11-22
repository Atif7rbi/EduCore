# HCTB-002A S3-R005 — Anti-Rollback and Crash-Recovery Design

Status: design-only. This document neither changes R004 nor authorizes a host launcher, reconciliation, acceptance issuance, or Phase B.

## Scope and R004 baseline

R004 creates one `INTENT_NO_RETRY` record using `O_EXCL`, writes the complete canonical record, fsyncs the record and journal directory, and rejects a pre-existing request key. The Broker requires the trusted journal descriptor and `JournalAnchor` before `sendmsg(SCM_RIGHTS)`. R005 adds an anti-rollback design around that contract; it must not create a second sender path.

## Trust model

Three root-owned resources are separately provisioned before the Controller starts and are never named by Controller IPC.

| Resource | Owner/mode | Purpose | Caller cannot provide |
|---|---|---|---|
| Journal directory | root:root 0700 | append-only INTENT records | path or directory FD |
| Genesis record | root:root 0600 | immutable journal namespace identity | genesis bytes or digest |
| External head anchor | root:root 0600 in distinct store | monotonic state | sequence, digest, or store path |

The harness pins descriptors and identities for all stores: device, inode, UID, GID, mode, type, and link count. The independent provisioner creates genesis and initial anchor before the Broker, Controller, or Verifier. It passes only pinned read-only descriptors to recovery and a capability-limited update descriptor to the anchor writer. The external anchor store is outside the journal directory, preventing replacement of an old complete journal directory together with its head.

Synthetic provisioning is not authenticated historical provenance. A future host implementation needs independently authenticated root provisioning and an external anti-rollback root of trust.

## Exact genesis canonicalization and digest authority

Genesis is a canonical UTF-8 JSON object with lexicographically sorted keys,
no insignificant whitespace, no duplicate keys, and RFC-8259 numbers only. It
contains exactly these non-derived fields:

`schema`, `journal_id`, `source_revision`, `fixture_identity`, and
`genesis_format_version`.

It contains neither `genesis_digest` nor a self-reference, signature, head,
sequence, previous digest, intent digest, acceptance field, or Phase-B field.
The canonical genesis digest is exactly `G = SHA256(genesis_canonical_bytes)`.
The independent provisioner computes `G` once from those bytes, seals the bytes
as the root-owned genesis record, and separately writes `G` into the initial
external head anchor. Therefore no genesis field depends on the digest being
calculated, and the anchor pins the digest of actual immutable bytes rather
than a formatted digest supplied by an untrusted party.

The sequence-zero anchor is canonical bytes containing exactly `schema`,
`journal_id`, `genesis_digest: G`, `sequence: 0`, and `head_digest: G`.
Recovery reads the genesis record through the pinned descriptor, recalculates
`G`, and requires equality with both anchor digest fields. Any mismatch is a
hard refusal.

## Authoritative intent ordering and head advancement

The journal order is the strictly increasing sequence assigned inside one
exclusive trusted critical section. It is not filesystem directory order,
mtime order, filename lexical order, or Controller input order. While holding
the journal-and-anchor exclusive lock, the trusted writer must:

1. Re-read and verify the current external anchor and reconstruct the pinned
   genesis-to-head chain.
2. Require the reconstructed sequence and digest to equal the anchor.
3. Reserve the next unique `(run_id, request_id)` with R004's `O_EXCL` intent
   creation; assign `n = current_sequence + 1` and persist the canonical
   intent carrying that sequence and its prior head digest.
4. Fully write and fsync the intent file, then fsync the journal directory.
5. Calculate `H[n] = SHA256(canonical({journal_id, genesis_digest:G,
   sequence:n, previous:H[n-1], intent_digest:I[n]}))`.
6. Create and fsync a new candidate head record in the trusted external anchor
   store, then atomically install it only if the verified old head remains the
   expected predecessor; fsync the anchor directory.
7. Re-open the installed head through its pinned descriptor and verify it
   matches `H[n]`; only then release the lock and permit R004's existing
   `sendmsg` path.

The lock spans reservation, sequence assignment, both fsync stages, and head
advancement. A competing writer must re-read the installed head after it gains
the lock; it cannot reuse a stale sequence or predecessor. The future
implementation must use a kernel-enforced exclusive lock and atomic
no-replace/CAS installation tied to a trusted directory FD. A failed lease,
unexpected pre-existing candidate, lost lock, or ambiguous syscall result is a
failure, never a retry or overwrite.

Ordinary filesystem ownership, `fsync`, and self-consistent hashes do not
protect against rollback of the entire journal and anchor stores together. The
external head must ultimately be anchored in an independently protected store
whose rollback is detectable by a separately provisioned trust root.

No genesis, intent, or head record grants acceptance or Phase-B authority.

## Recovery policy

Before any Broker transfer or claim, recovery obtains the same exclusive lock,
recomputes the complete sequence-ordered chain from trusted genesis, and
compares it to the external anchor. It fails closed on any descriptor,
canonical-record, sequence, digest, ownership, identity, lock, or atomic-head
mismatch. It must never choose a "closest" state or repair either store.

An existing canonical intent is permanently consumed. Recovery must not send, retry, complete, delete, or rewrite it, even after an interrupted head update or ambiguous `SCM_RIGHTS` result. Only a separately authorized, operator-owned reconciliation process could resolve evidence; it remains outside R005 and cannot mint acceptance authority.

| Condition | Response |
|---|---|
| Missing genesis or anchor | Refuse all transfers |
| Journal ahead of anchor / stale head | Interrupted or rollback-ambiguous; refuse and retain both stores |
| Anchor ahead of journal / truncation | Interrupted or rollback-ambiguous; refuse and retain both stores |
| Conflicting head or duplicate sequence | Refuse |
| Corrupt or noncanonical record | Refuse |
| Symlink, substituted directory, metadata change | Refuse |
| Lock or fsync uncertainty | Refuse; no automatic retry |
| Ambiguous send after INTENT | Keep consumed; no replay |

## Crash-point matrix

| Interruption | Required classification |
|---|---|
| Before O_EXCL INTENT creation | No record; fresh claim only after anchor verification |
| After write, before file fsync | Uncertain; refuse and preserve artifacts |
| After file fsync, before directory fsync | Uncertain; refuse and preserve artifacts |
| After directory fsync, before head update | Mismatch; refuse; no send/replay |
| After anchored head, before SCM_RIGHTS | Consumed; refuse automatic replay |
| After ambiguous SCM_RIGHTS | Consumed; refuse automatic replay |

Deterministic fault injection can validate classification only. It cannot prove power-loss persistence, storage-controller ordering, or filesystem crash semantics.

## Adversarial tests and minimal plan

The next implementation gate must test: old valid journal with newer anchored head; old anchor with newer journal; deleted/truncated/reordered/duplicate intents; substituted root or anchor directory; symlink/hard-link attack; malformed genesis/anchor; concurrent claims/anchor writers; interrupted update; stale lock; metadata changes; and every crash point above. Each unsafe state must yield nonzero failure, no sendmsg, no acceptance artifact, and no Phase-B capability.

1. Add separate `journal_anchor.py` and isolated tests; retain existing Broker enforcement.
2. Extend trusted provisioning only under a new review gate to pin genesis/head descriptors before privilege separation.
3. Add deterministic fault-injection and disposable privileged-runner tests; do not run them on the host.
4. Require independent review before runner or host changes.

## Residual risks

R005 does not establish hardware-backed anti-rollback, real power-loss durability, or authenticated historical provenance. Synthetic tests do not prove real host AppArmor, storage, DAC, or crash behavior.
