#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
readonly harness=pa001harness controller=pa001controller verifier=pa001verifier
stage=INITIALIZE
on_err(){
  local status=$?
  printf 'PA001_HARNESS_ERROR stage=%s line=%s status=%s\n' "$stage" "$1" "$status" >&2
  exit "$status"
}
fail_explicit(){
  local line=$1 status=$2
  printf 'PA001_HARNESS_ERROR stage=%s line=%s status=%s\n' "$stage" "$line" "$status" >&2
  exit "$status"
}
trap 'on_err "$LINENO"' ERR
root=''
root_created=0
created_identities=()
create_disposable_identities(){
  local identity
  for identity in "$harness" "$controller" "$verifier"; do
    if id "$identity" >/dev/null 2>&1; then
      fail_explicit "$LINENO" 70
    fi
    sudo useradd --system --no-create-home --shell /usr/sbin/nologin "$identity"
    created_identities+=("$identity")
  done
}
create_root_staging(){
  local create_status
  if root="$(sudo mktemp -d /tmp/educore-pa001-boundary.XXXXXX)"; then
    :
  else
    create_status=$?
    fail_explicit "$LINENO" "$create_status"
  fi
  if [[ "${root#/tmp/educore-pa001-boundary.}" == "$root" ]] || [[ ! -d "$root" ]] || [[ -L "$root" ]]; then
    fail_explicit "$LINENO" 1
  fi
  root_created=1
  readonly root
}
assert_controller_traversal(){
  sudo -u "$controller" sh -c '
    path=$1
    while :; do
      test -x "$path" || exit 1
      test "$path" = / && exit 0
      path=$(dirname -- "$path")
    done
  ' sh "$integration_stage_dir"
}
assert_ipc_traversal(){
  local identity
  for identity in "$controller" "$verifier"; do
    sudo -u "$identity" sh -c 'test -x "$1" && test ! -w "$1"' sh "$ipc_dir"
  done
}
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
run_ipc_directory_tests(){
  local fixture ipc locked
  fixture="$(mktemp -d)"
  trap 'rm -rf -- "$fixture"' RETURN
  ipc="$fixture/ipc"
  locked="$fixture/locked"
  mkdir -m 0755 -- "$ipc"
  mkdir -m 0555 -- "$locked"
  test -x "$ipc" && test -w "$ipc"
  test -x "$locked" && test ! -w "$locked"
  python3 - "$ipc" "$locked" <<'PY'
import os
import socket
import sys

writable = os.path.join(sys.argv[1], "controller.sock")
locked = os.path.join(sys.argv[2], "controller.sock")
server = socket.socket(socket.AF_UNIX)
try:
    server.bind(writable)
    if not os.path.exists(writable):
        raise RuntimeError("socket missing after bind")
finally:
    server.close()
    if os.path.exists(writable):
        os.unlink(writable)
denied = socket.socket(socket.AF_UNIX)
try:
    try:
        denied.bind(locked)
    except PermissionError:
        pass
    else:
        raise RuntimeError("socket bind unexpectedly allowed")
finally:
    denied.close()
PY
  printf '%s\n' 'IPC_DIRECTORY_SOCKET_TESTS=PASS'
}
if [[ "${1:-}" == '--test-absence-predicate' ]]; then
  stage=UNPRIVILEGED_PREDICATE_TEST
  run_absence_predicate_tests
  exit 0
fi
if [[ "${1:-}" == '--test-ipc-directory' ]]; then
  stage=UNPRIVILEGED_IPC_DIRECTORY_TEST
  run_ipc_directory_tests
  exit 0
fi
if [[ "${1:-}" == '--test-diagnostic-trap' ]]; then
  stage=UNPRIVILEGED_DIAGNOSTIC_TEST
  false
fi
if [[ "${1:-}" == '--test-explicit-identity-exit' ]]; then
  stage=CREATE_DISPOSABLE_IDENTITIES
  fail_explicit "$LINENO" 70
fi
if [[ "${1:-}" == '--test-explicit-replay-exit' ]]; then
  stage=VERIFY_REPLAY_ARTIFACT_ABSENCE
  fail_explicit "$LINENO" 72
fi
if [[ "${1:-}" == '--test-mktemp-failure' ]]; then
  stage=CREATE_ROOT_STAGING
  sudo(){ return 73; }
  create_root_staging
fi
if [[ "${1:-}" == '--test-identity-collision' ]]; then
  stage=CREATE_DISPOSABLE_IDENTITIES
  id(){ return 0; }
  sudo(){ return 99; }
  create_disposable_identities
fi
readonly out="$PWD/out/pa001-process-boundary"
mkdir -p "$out"; : > "$out/result.txt"
cleanup(){
  local original_status=$? cleanup_status=0 index
  # An EXIT trap return does not reliably replace the shell status. Disable the
  # trap before the final explicit exit so cleanup cannot recurse.
  trap - EXIT
  for ((index=${#created_identities[@]}-1; index>=0; index--)); do
    sudo userdel "${created_identities[index]}" 2>/dev/null || cleanup_status=1
  done
  if [[ "$root_created" -eq 1 && "$root" == /tmp/educore-pa001-boundary.* && -d "$root" && ! -L "$root" ]]; then
    sudo rm -rf -- "$root" || cleanup_status=1
  fi
  if [[ "$original_status" -ne 0 ]]; then
    exit "$original_status"
  fi
  if [[ "$cleanup_status" -ne 0 ]]; then
    printf 'PA001_HARNESS_ERROR stage=CLEANUP line=0 status=%s\n' "$cleanup_status" >&2
    exit "$cleanup_status"
  fi
  exit 0
}
trap cleanup EXIT
if [[ "${1:-}" == '--test-exit-trap-status' ]]; then
  scenario=${2:?missing-scenario}
  sudo(){
    case "$1" in
      userdel)
        [[ "$scenario" == success-identity-cleanup-failure || "$scenario" == failure-cleanup-failure ]] && return 75
        return 0
        ;;
      rm)
        if [[ "$scenario" == success-staging-cleanup-failure ]]; then
          /bin/rm -rf -- "$4"
          return 76
        fi
        return 0
        ;;
      *) return 0 ;;
    esac
  }
  case "$scenario" in
    success-cleanup-success)
      exit 0
      ;;
    success-identity-cleanup-failure)
      created_identities=(pa001harness)
      exit 0
      ;;
    success-staging-cleanup-failure)
      root="$(mktemp -d /tmp/educore-pa001-boundary.XXXXXX)"
      root_created=1
      exit 0
      ;;
    failure-cleanup-success)
      created_identities=(pa001harness)
      exit 66
      ;;
    failure-cleanup-failure)
      created_identities=(pa001harness)
      exit 66
      ;;
    *) exit 64 ;;
  esac
fi
if [[ "${1:-}" == '--test-partial-identity-creation' ]]; then
  stage=CREATE_DISPOSABLE_IDENTITIES
  useradd_calls=0
  id(){ return 1; }
  sudo(){
    if [[ "$1" == useradd ]]; then
      useradd_calls=$((useradd_calls + 1))
      [[ "$useradd_calls" -eq 2 ]] && return 74
      return 0
    fi
    if [[ "$1" == userdel ]]; then
      printf 'CLEANUP_USERDEL=%s\n' "$2"
      return 0
    fi
    return 0
  }
  create_disposable_identities
fi
stage=CREATE_ROOT_STAGING
create_root_staging
readonly integration_source="$PWD/tests/process_boundary_integration.py"
readonly integration_sha256=1d29c521753b87cf7b5844da15203efe51811f7533f25061d826645ad73736ea
readonly integration_stage_dir="$root/runner-code"
readonly integration_script="$integration_stage_dir/process_boundary_integration.py"
readonly runner_uid="$(id -u)"
readonly runner_gid="$(id -g)"
readonly ipc_dir="$root/ipc"
stage=VERIFY_HOSTED_RUNNER
test "${RUNNER_ENVIRONMENT:-}" = github-hosted
stage=CREATE_DISPOSABLE_IDENTITIES
create_disposable_identities
stage=VERIFY_ROOT_STAGING
test "${root#/tmp/educore-pa001-boundary.}" != "$root"
test ! -L "$root"
test "$(sudo stat -c '%u:%g:%a' "$root")" = '0:0:700'
sudo chmod 0755 "$root"
test "$(sudo stat -c '%u:%g:%a' "$root")" = '0:0:755'
stage=CREATE_IPC_DIRECTORY
test "$runner_uid" -ne 0
sudo install -d -o "$runner_uid" -g "$runner_gid" -m 0755 "$ipc_dir"
stage=VERIFY_IPC_DIRECTORY
test ! -L "$ipc_dir"
test "$(sudo stat -c '%u:%g:%a' "$ipc_dir")" = "$runner_uid:$runner_gid:755"
test -x "$ipc_dir" && test -w "$ipc_dir"
assert_ipc_traversal
stage=CREATE_PROTECTED_FIXTURES
sudo install -d -o root -g root -m 0700 "$root/journal-trust"
sudo install -d -o "$controller" -g "$controller" -m 0700 "$root/evidence"
sudo install -o root -g root -m 0600 /dev/null "$root/journal-trust/journal"
stage=VERIFY_SOURCE_SCRIPT
test -f "$integration_source" && test ! -L "$integration_source"
test "$(stat -c '%a' "$integration_source")" = '644'
test "$(sha256sum "$integration_source" | awk '{print $1}')" = "$integration_sha256"
stage=STAGE_RUNNER_SCRIPT
sudo install -d -o root -g root -m 0755 "$integration_stage_dir"
sudo install -o root -g root -m 0644 "$integration_source" "$integration_script"
stage=VERIFY_STAGED_RUNNER_SCRIPT
test "$(sudo sha256sum "$integration_script" | awk '{print $1}')" = "$integration_sha256"
test "$(sudo stat -c '%u:%g:%a' "$integration_stage_dir")" = '0:0:755'
test "$(sudo stat -c '%u:%g:%a' "$integration_script")" = '0:0:644'
stage=VERIFY_CONTROLLER_TRAVERSAL
assert_controller_traversal
stage=VERIFY_CONTROLLER_SCRIPT_ACCESS
sudo -u "$controller" sh -c 'test -r "$1" && test ! -w "$1" && test -x "$(dirname "$1")" && test ! -w "$(dirname "$1")"' sh "$integration_script"
stage=VERIFY_CONTROLLER_JOURNAL_DENIAL
sudo -u "$controller" sh -c 'test ! -r "$1"' sh "$root/journal-trust/journal"
stage=CAPTURE_JOURNAL_DIGEST
journal_sha="$(sudo sha256sum "$root/journal-trust/journal" | awk '{print $1}')"
stage=WRITE_INTERMEDIATE_EVIDENCE
sudo -u "$controller" python3 "$integration_script" controller --evidence "$root/evidence"
# Content hashes are synthetic fixture-integrity values.  They are deliberately
# distinct from any independently authenticated provenance anchor used on host.
evidence_sha="$(sudo sha256sum "$root/evidence/intermediate.json" | awk '{print $1}')"
stage=VERIFY_ROOT_JOURNAL_ACCESS
sudo test -r "$root/journal-trust/journal" # Root verifier only
stage=START_IPC_SERVER
controller_uid="$(id -u "$controller")"
socket_path="$ipc_dir/controller.sock"
ready_path="$out/ipc.ready"
test ! -e "$socket_path"
python3 "$integration_script" server --socket "$socket_path" --controller-uid "$controller_uid" --result "$out/ipc.txt" --ready "$ready_path" &
server_pid=$!
for _ in $(seq 1 50); do test -f "$ready_path" && break; sleep 0.1; done
test -f "$ready_path"
stage=VERIFY_IPC_PEER_CREDENTIALS
sudo -u "$controller" python3 "$integration_script" client --socket "$socket_path" --authorized yes --payload '{"op":"validate-intermediate"}'
sudo -u "$verifier" python3 "$integration_script" client --socket "$socket_path" --authorized no --payload '{"op":"validate-intermediate"}'
sudo -u "$controller" python3 "$integration_script" client --socket "$socket_path" --authorized no --payload '{"op":"validate-intermediate","path":"/etc/passwd"}'
wait "$server_pid"
stage=PREPARE_REPLAY_FIXTURE
replay="$root/replay"; sudo install -d -o "$controller" -g "$controller" -m 0700 "$replay"
stage=INTERRUPT_REPLAY_FIXTURE
sudo -u "$controller" python3 "$integration_script" controller --evidence "$replay" --interrupt > "$out/checkpoint.txt"
sudo dd if="$replay/intent" of="$out/intent-before.bin" status=none
intent_before="$(sudo sha256sum "$replay/intent" | awk '{print $1}')"
stage=REJECT_REPLAY
if sudo -u "$controller" python3 "$integration_script" controller --evidence "$replay" > "$out/replay.txt" 2>&1; then
  replay_status=0
else
  replay_status=$?
fi
stage=VERIFY_REPLAY_REJECTION
test "$replay_status" -eq 75
grep -qx 'REPLAY_REJECTED' "$out/replay.txt"
intent_after="$(sudo sha256sum "$replay/intent" | awk '{print $1}')"
test "$intent_before" = "$intent_after"
sudo cmp -- "$out/intent-before.bin" "$replay/intent"
stage=VERIFY_REPLAY_ARTIFACT_ABSENCE
if ! assert_replay_artifacts_absent; then
  fail_explicit "$LINENO" 72
fi
# Negative fixture checks: either prohibited artifact must be visible to the
# Root Harness and make the absence assertion fail.
for artifact in acceptance.json phase-b-capability; do
  sudo -u "$controller" touch "$replay/$artifact"
  if assert_replay_artifacts_absent; then
    fail_explicit "$LINENO" 72
  fi
  sudo -u "$controller" rm -f -- "$replay/$artifact"
done
if ! assert_replay_artifacts_absent; then
  fail_explicit "$LINENO" 72
fi
printf '%s\n' 'REPLAY_PROHIBITED_ARTIFACT_DETECTION=PASS' > "$out/replay-artifact-negative.txt"
stage=VERIFY_INTERMEDIATE_EVIDENCE
sudo python3 "$integration_script" verifier --root "$root" --journal-sha "$journal_sha" --evidence-sha "$evidence_sha" --controller-uid "$controller_uid" > "$out/result.txt"
grep -qx 'PA001_PROCESS_BOUNDARY=PASS' "$out/result.txt"
