#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
readonly harness=pa001harness controller=pa001controller verifier=pa001verifier
assert_replay_artifacts_absent(){
  # The production path invokes sudo as the synthetic Root Harness. A failed
  # predicate must be propagated explicitly; never let a later command mask it.
  sudo test -d "$replay" || return 1
  sudo test ! -e "$replay/acceptance.json" || return 1
  sudo test ! -e "$replay/phase-b-capability" || return 1
  return 0
}
run_absence_predicate_tests(){
  local fixture
  fixture="$(mktemp -d)"
  trap 'rm -rf -- "$fixture"' RETURN
  replay="$fixture/replay"
  mkdir -m 0700 -- "$replay"
  # Unprivileged test double: exercise the exact predicate without invoking
  # Root or the full integration Harness.
  sudo(){ "$@"; }
  assert_replay_artifacts_absent
  touch "$replay/acceptance.json"
  if assert_replay_artifacts_absent; then return 1; fi
  rm -f -- "$replay/acceptance.json"
  touch "$replay/phase-b-capability"
  if assert_replay_artifacts_absent; then return 1; fi
  rm -f -- "$replay/phase-b-capability"
  touch "$replay/acceptance.json" "$replay/phase-b-capability"
  if assert_replay_artifacts_absent; then return 1; fi
  printf '%s\n' 'REPLAY_ABSENCE_PREDICATE_TESTS=PASS'
}
if [[ "${1:-}" == '--test-absence-predicate' ]]; then
  run_absence_predicate_tests
  exit 0
fi
readonly out="$PWD/out/pa001-process-boundary" root="$RUNNER_TEMP/pa001-boundary"
readonly integration_source="$PWD/tests/process_boundary_integration.py"
readonly integration_sha256=1d29c521753b87cf7b5844da15203efe51811f7533f25061d826645ad73736ea
readonly integration_stage_dir="$root/runner-code"
readonly integration_script="$integration_stage_dir/process_boundary_integration.py"
mkdir -p "$out"; : > "$out/result.txt"
cleanup(){ sudo userdel "$controller" 2>/dev/null || true; sudo userdel "$verifier" 2>/dev/null || true; sudo userdel "$harness" 2>/dev/null || true; sudo rm -rf -- "$root"; }
trap cleanup EXIT
test "${RUNNER_ENVIRONMENT:-}" = github-hosted
for u in "$harness" "$controller" "$verifier"; do ! id "$u" >/dev/null 2>&1 || exit 70; sudo useradd --system --no-create-home --shell /usr/sbin/nologin "$u"; done
sudo install -d -o root -g root -m 0755 "$root"
sudo install -d -o root -g root -m 0700 "$root/journal-trust"
sudo install -d -o "$controller" -g "$controller" -m 0700 "$root/evidence"
sudo install -o root -g root -m 0600 /dev/null "$root/journal-trust/journal"
test -f "$integration_source" && test ! -L "$integration_source"
test "$(sha256sum "$integration_source" | awk '{print $1}')" = "$integration_sha256"
sudo install -d -o root -g root -m 0755 "$integration_stage_dir"
sudo install -o root -g root -m 0644 "$integration_source" "$integration_script"
test "$(sudo sha256sum "$integration_script" | awk '{print $1}')" = "$integration_sha256"
test "$(sudo stat -c '%u:%g:%a' "$integration_stage_dir")" = '0:0:755'
test "$(sudo stat -c '%u:%g:%a' "$integration_script")" = '0:0:644'
sudo -u "$controller" sh -c 'test -r "$1" && test ! -w "$1" && test -x "$(dirname "$1")" && test ! -w "$(dirname "$1")"' sh "$integration_script"
sudo -u "$controller" sh -c 'test ! -r "$1"' sh "$root/journal-trust/journal"
journal_sha="$(sudo sha256sum "$root/journal-trust/journal" | awk '{print $1}')"
sudo -u "$controller" python3 "$integration_script" controller --evidence "$root/evidence"
# Content hashes are synthetic fixture-integrity values.  They are deliberately
# distinct from any independently authenticated provenance anchor used on host.
evidence_sha="$(sudo sha256sum "$root/evidence/intermediate.json" | awk '{print $1}')"
sudo test -r "$root/journal-trust/journal" # Root verifier only
controller_uid="$(id -u "$controller")"
socket_path="$root/controller.sock"
ready_path="$out/ipc.ready"
python3 "$integration_script" server --socket "$socket_path" --controller-uid "$controller_uid" --result "$out/ipc.txt" --ready "$ready_path" &
server_pid=$!
for _ in $(seq 1 50); do test -f "$ready_path" && break; sleep 0.1; done
test -f "$ready_path"
sudo -u "$controller" python3 "$integration_script" client --socket "$socket_path" --authorized yes --payload '{"op":"validate-intermediate"}'
sudo -u "$verifier" python3 "$integration_script" client --socket "$socket_path" --authorized no --payload '{"op":"validate-intermediate"}'
sudo -u "$controller" python3 "$integration_script" client --socket "$socket_path" --authorized no --payload '{"op":"validate-intermediate","path":"/etc/passwd"}'
wait "$server_pid"
replay="$root/replay"; sudo install -d -o "$controller" -g "$controller" -m 0700 "$replay"
sudo -u "$controller" python3 "$integration_script" controller --evidence "$replay" --interrupt > "$out/checkpoint.txt"
sudo dd if="$replay/intent" of="$out/intent-before.bin" status=none
intent_before="$(sudo sha256sum "$replay/intent" | awk '{print $1}')"
set +e
sudo -u "$controller" python3 "$integration_script" controller --evidence "$replay" > "$out/replay.txt" 2>&1
replay_status=$?
set -e
test "$replay_status" -eq 75
grep -qx 'REPLAY_REJECTED' "$out/replay.txt"
intent_after="$(sudo sha256sum "$replay/intent" | awk '{print $1}')"
test "$intent_before" = "$intent_after"
sudo cmp -- "$out/intent-before.bin" "$replay/intent"
assert_replay_artifacts_absent
# Negative fixture checks: either prohibited artifact must be visible to the
# Root Harness and make the absence assertion fail.
for artifact in acceptance.json phase-b-capability; do
  sudo -u "$controller" touch "$replay/$artifact"
  if assert_replay_artifacts_absent; then exit 72; fi
  sudo -u "$controller" rm -f -- "$replay/$artifact"
done
assert_replay_artifacts_absent
printf '%s\n' 'REPLAY_PROHIBITED_ARTIFACT_DETECTION=PASS' > "$out/replay-artifact-negative.txt"
sudo python3 "$integration_script" verifier --root "$root" --journal-sha "$journal_sha" --evidence-sha "$evidence_sha" --controller-uid "$controller_uid" > "$out/result.txt"
grep -qx 'PA001_PROCESS_BOUNDARY=PASS' "$out/result.txt"
