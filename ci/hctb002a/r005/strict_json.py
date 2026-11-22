"""R007: strict JSON decoding at untrusted IPC/evidence boundaries.

This module does not change the frozen S1 wire schema or serialization.
"""
import json


class DuplicateJSONKey(ValueError):
    pass


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise DuplicateJSONKey('duplicate json key')
        result[key] = value
    return result


def _reject_constant(value):
    raise ValueError('nonfinite json constant')


def strict_json_loads(payload):
    """Decode UTF-8 JSON rejecting duplicate keys and non-finite constants."""
    if not isinstance(payload, bytes):
        raise ValueError('json input must be bytes')
    try:
        return json.loads(payload.decode('utf-8'), object_pairs_hook=_unique_object,
                          parse_constant=_reject_constant)
    except UnicodeError as exc:
        raise ValueError('invalid utf8') from exc
