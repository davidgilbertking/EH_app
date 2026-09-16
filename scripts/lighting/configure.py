"""Obtain one selected Tuya device's local key; never list devices or change the lamp.

Run with storage/app/private/lighting/venv/bin/python scripts/lighting/configure.py.
Cloud Access ID / Secret are entered without echo and are never saved. Only the
selected device's local connection settings are written to private device.json.
"""

import argparse
import getpass
import importlib
import ipaddress
import json
import logging
import os
import re
import signal
import stat
import sys
from contextlib import contextmanager, redirect_stderr, redirect_stdout
from datetime import datetime, timezone
from io import StringIO
from pathlib import Path
from tempfile import NamedTemporaryFile
from unittest.mock import patch
from urllib.parse import urlsplit

PRIVATE_DIRECTORY = Path(__file__).resolve().parents[2] / "storage/app/private/lighting"
REGIONS = {
    "cn": "openapi.tuyacn.com", "us": "openapi.tuyaus.com",
    "us-e": "openapi-ueaz.tuyaus.com", "eu": "openapi.tuyaeu.com",
    "eu-w": "openapi-weaz.tuyaeu.com", "in": "openapi.tuyain.com",
    "sg": "openapi-sg.iotbing.com",
}
# TinyTuya 1.20 set_version(3.2) invokes detect_available_dps implicitly.
READ_ONLY_VERSIONS = ("3.1", "3.3", "3.4", "3.5")


class DiagnosticError(Exception):
    """Only a static, safe code may leave the diagnostic boundary."""


class DeadlineExceeded(BaseException):
    """Not swallowed by TinyTuya's internal broad Exception retry handlers."""


def utc_now():
    return datetime.now(timezone.utc).isoformat()


def device_id(value):
    if not isinstance(value, str) or not re.fullmatch(r"[A-Za-z0-9_-]{6,128}", value):
        raise DiagnosticError("invalid_device_id")
    return value


def local_key(value):
    # TinyTuya encodes the AES local key as latin1. Require exactly 16 printable
    # ASCII bytes, including punctuation; never trim, repair, or guess a key.
    if not isinstance(value, str) or len(value) != 16 or any(not 33 <= ord(c) <= 126 for c in value):
        raise DiagnosticError("invalid_local_key")
    return value


def local_ip(value):
    try:
        address = ipaddress.IPv4Address(value)
    except (ipaddress.AddressValueError, TypeError):
        raise DiagnosticError("invalid_lan_ip") from None
    networks = ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16")
    if not any(address in ipaddress.IPv4Network(network) for network in networks):
        raise DiagnosticError("invalid_lan_ip")
    return str(address)


def protocol(value):
    if str(value) not in READ_ONLY_VERSIONS:
        raise DiagnosticError("unsupported_read_only_protocol")
    return str(value)


def prepare_private_directory(directory):
    directory = Path(directory)
    if directory.is_symlink():
        raise DiagnosticError("private_directory_symlink")
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    os.chmod(directory, 0o700)
    return directory


def save_private(directory, filename, payload):
    directory = prepare_private_directory(directory)
    if Path(filename).name != filename or not re.fullmatch(r"[A-Za-z0-9_.-]+\.json", filename):
        raise DiagnosticError("invalid_private_filename")
    temporary = None
    try:
        with NamedTemporaryFile(mode="w", encoding="utf-8", dir=directory, delete=False) as handle:
            temporary = handle.name
            os.fchmod(handle.fileno(), 0o600)
            json.dump(payload, handle, indent=2, ensure_ascii=True)
            handle.write("\n")
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, directory / filename)
    finally:
        if temporary is not None and os.path.exists(temporary):
            os.unlink(temporary)


def read_private(path, require_owner_only=True):
    path = Path(path)
    if path.is_symlink() or not path.is_file():
        raise DiagnosticError("private_file_missing_or_invalid")
    metadata = path.stat()
    if metadata.st_size > 65536:
        raise DiagnosticError("private_file_too_large")
    if require_owner_only and (stat.S_IMODE(metadata.st_mode) & 0o077 or metadata.st_uid != os.getuid()):
        raise DiagnosticError("private_file_permissions")
    try:
        result = json.loads(path.read_text(encoding="utf-8"))
    except (ValueError, UnicodeError):
        raise DiagnosticError("invalid_private_json") from None
    if not isinstance(result, dict):
        raise DiagnosticError("invalid_private_json")
    return result


@contextmanager
def quiet_library():
    # TinyTuya DEBUG logs can contain keys, auth headers and raw responses.
    previous = logging.root.manager.disable
    logging.disable(logging.CRITICAL)
    try:
        with redirect_stdout(StringIO()), redirect_stderr(StringIO()):
            yield
    finally:
        logging.disable(previous)


@contextmanager
def deadline(seconds):
    if not hasattr(signal, "setitimer"):
        raise DiagnosticError("deadline_not_supported")
    previous_handler = signal.getsignal(signal.SIGALRM)
    previous_timer = signal.getitimer(signal.ITIMER_REAL)
    if previous_timer != (0.0, 0.0):
        raise DiagnosticError("deadline_already_active")

    def expired(_number, _frame):
        raise DeadlineExceeded()

    signal.signal(signal.SIGALRM, expired)
    signal.setitimer(signal.ITIMER_REAL, seconds)
    try:
        yield
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)
        signal.signal(signal.SIGALRM, previous_handler)


class SelectedDeviceTransport:
    """Constrain TinyTuya 1.20's otherwise unbounded requests.get calls."""

    def __init__(self, session, region, selected_id):
        self.session = session
        self.host = REGIONS[region]
        self.allowed = {"/v1.0/token?grant_type=1", f"/v1.0/devices/{selected_id}"}
        self.calls = 0

    def get(self, url, **kwargs):
        parsed = urlsplit(url)
        resource = parsed.path + ("?" + parsed.query if parsed.query else "")
        if parsed.scheme != "https" or parsed.netloc != self.host or resource not in self.allowed:
            raise DiagnosticError("cloud_request_outside_selected_device")
        self.calls += 1
        if self.calls > 4:
            raise DiagnosticError("cloud_request_limit")
        response = self.session.get(url, timeout=(5, 10), allow_redirects=False, **kwargs)
        if not 200 <= response.status_code < 300:
            raise DiagnosticError("cloud_http_error")
        return response

    def request(self, *_args, **_kwargs):
        raise DiagnosticError("cloud_write_forbidden")


def fetch_local_key(region, access_id, secret, selected_id):
    if region not in REGIONS:
        raise DiagnosticError("invalid_region")
    selected_id = device_id(selected_id)
    if not access_id or not secret:
        raise DiagnosticError("cloud_credentials_required")
    cloud = None
    with quiet_library(), deadline(45):
        module = importlib.import_module("tinytuya.Cloud")
        tinytuya = importlib.import_module("tinytuya")
        if tinytuya.__version__ != "1.20.0":
            raise DiagnosticError("tinytuya_version_mismatch")
        requests = importlib.import_module("requests")
        with requests.Session() as session:
            # Do not inherit unrelated proxy/netrc credentials from this machine.
            session.trust_env = False
            transport = SelectedDeviceTransport(session, region, selected_id)
            with patch.object(module, "requests", transport):
                try:
                    cloud = module.Cloud(apiRegion=region, apiKey=access_id, apiSecret=secret,
                                         apiDeviceID=selected_id, configFile=os.devnull)
                    if not cloud.token:
                        raise DiagnosticError("cloud_authentication_failed")
                    response = cloud.cloudrequest(f"/v1.0/devices/{selected_id}", action="GET")
                    if not isinstance(response, dict) or response.get("success") is not True:
                        raw_code = response.get("code") if isinstance(response, dict) else None
                        code = str(raw_code) if isinstance(raw_code, (str, int)) else ""
                        suffix = "_" + code if re.fullmatch(r"[0-9]{1,8}", code) else ""
                        raise DiagnosticError("cloud_device_details_failed" + suffix)
                    details = response.get("result")
                    if not isinstance(details, dict) or details.get("id") != selected_id:
                        raise DiagnosticError("cloud_device_mismatch")
                    return local_key(details.get("local_key"))
                finally:
                    if cloud is not None:
                        cloud.apiKey = ""
                        cloud.apiSecret = ""
                        cloud.token = None


def configure_connection(region, access_id, secret, selected_id, ip, version,
                         directory=PRIVATE_DIRECTORY, fetcher=fetch_local_key):
    selected_id, ip, version = device_id(selected_id), local_ip(ip), protocol(version)
    if region not in REGIONS:
        raise DiagnosticError("invalid_region")
    key = local_key(fetcher(region, access_id, secret, selected_id))
    save_private(directory, "device.json", {
        "schema_version": 1, "configured_at": utc_now(), "device_id": selected_id,
        "ip": ip, "version": version, "local_key": key,
    })
    return {"ok": True, "saved_to": "storage/app/private/lighting/device.json",
            "cloud_credentials_saved": False, "device_state_read": False, "lighting_changed": False}


def suggested_device(directory):
    discovery = directory / "discovery.json"
    if discovery.is_file():
        devices = read_private(discovery).get("devices", [])
        if isinstance(devices, list) and len(devices) == 1 and isinstance(devices[0], dict):
            return device_id(devices[0].get("device_id"))
        # Do not substitute an older ID when fresh discovery is ambiguous.
        return ""
    check = directory / "connection-check.json"
    return device_id(read_private(check).get("device_id")) if check.is_file() else ""


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--device-id", help="Selected current lamp ID, verified after pairing")
    parser.add_argument("--ip", help="Current LAN IPv4 address confirmed after pairing")
    parser.add_argument("--version", choices=READ_ONLY_VERSIONS, help="Protocol version confirmed after pairing; no default")
    parser.add_argument("--region", choices=tuple(REGIONS), help="Tuya project's data-center region")
    args = parser.parse_args(argv)
    try:
        if not sys.stdin.isatty():
            raise DiagnosticError("interactive_terminal_required")
        previous_id = suggested_device(PRIVATE_DIRECTORY) if args.device_id is None else ""
        selected = args.device_id or input(
            f"Device ID verified after latest pairing{(' [suggested ' + previous_id + ']') if previous_id else ''}: "
        ).strip() or previous_id
        if args.device_id is None and selected == previous_id:
            if input("Confirm this Device ID still belongs to this lamp after re-pairing (type YES): ") != "YES":
                raise DiagnosticError("device_selection_not_confirmed")
        ip = args.ip or input("Current lamp LAN IPv4 after pairing: ").strip()
        version = args.version or input("Confirmed protocol (3.1/3.3/3.4/3.5; no default): ").strip()
        region = args.region or input("Tuya project region (cn/us/us-e/eu/eu-w/in/sg): ").strip()
        # Validate selection before asking for credentials or contacting the cloud.
        selected, ip, version = device_id(selected), local_ip(ip), protocol(version)
        if region not in REGIONS:
            raise DiagnosticError("invalid_region")
        access_id = getpass.getpass("Tuya Access ID (hidden): ")
        secret = getpass.getpass("Tuya Access Secret (hidden; not saved): ")
        result = configure_connection(region, access_id, secret, selected, ip, version)
    except DiagnosticError as error:
        result = {"ok": False, "error": str(error), "lighting_changed": False}
    except DeadlineExceeded:
        result = {"ok": False, "error": "cloud_deadline_exceeded", "lighting_changed": False}
    except (KeyboardInterrupt, EOFError):
        result = {"ok": False, "error": "cancelled", "lighting_changed": False}
    except Exception:
        # Never print an exception, request object, raw response, or traceback.
        result = {"ok": False, "error": "configuration_failed", "lighting_changed": False}
    print(json.dumps(result, indent=2))
    return 0 if result["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
