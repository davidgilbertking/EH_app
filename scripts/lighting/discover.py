"""Discover Tuya LAN announcements without polling or changing device state."""

import argparse
import ipaddress
import json
import os
import select
import socket
import time
from contextlib import ExitStack, chdir
from datetime import datetime, timezone
from pathlib import Path
from tempfile import NamedTemporaryFile, TemporaryDirectory

from tinytuya import __version__, decrypt_udp, scanner


def lan_address(value):
    try:
        address = ipaddress.IPv4Address(value)
    except ipaddress.AddressValueError as error:
        raise argparse.ArgumentTypeError("Expected a local IPv4 address") from error
    if not any(address in ipaddress.IPv4Network(network) for network in
               ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16")):
        raise argparse.ArgumentTypeError("Use the device's private LAN address")
    return str(address)


def discover_target(address, seconds):
    """Request discovery from one address when Wi-Fi broadcasts are unavailable."""
    with ExitStack() as stack:
        route = stack.enter_context(socket.socket(socket.AF_INET, socket.SOCK_DGRAM))
        # UDP connect selects an interface without transmitting a packet.
        route.connect((address, 7000))
        local_address = route.getsockname()[0]
        listeners = []
        for port in (6666, 6667, 7000):
            listener = stack.enter_context(socket.socket(socket.AF_INET, socket.SOCK_DGRAM))
            if hasattr(socket, "SO_REUSEPORT"):
                listener.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEPORT, 1)
            listener.bind(("", port))
            listeners.append(listener)

        sender = stack.enter_context(socket.socket(socket.AF_INET, socket.SOCK_DGRAM))
        sender.bind((local_address, 0))
        listeners.append(sender)
        interfaces = {local_address: {"broadcast": address, "socket": sender}}
        deadline = time.monotonic() + seconds
        next_request = 0
        while time.monotonic() < deadline:
            if time.monotonic() >= next_request:
                # REQ_DEVINFO only; no connection, DP query, or control command.
                scanner.send_discovery_request(interfaces)
                next_request = time.monotonic() + 3
            timeout = max(0, min(deadline, next_request) - time.monotonic())
            ready, _, _ = select.select(listeners, [], [], timeout)
            for listener in ready:
                payload, source = listener.recvfrom(8192)
                if source[0] != address:
                    continue
                try:
                    device = json.loads(decrypt_udp(payload))
                except (ValueError, TypeError, UnicodeError):
                    continue
                if isinstance(device, dict) and device.get("gwId"):
                    # Trust the responding peer's address, not a payload IP.
                    device["ip"] = address
                    return {address: device}
        return {}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--seconds", type=int, default=15, choices=range(1, 61), metavar="1..60")
    parser.add_argument("--ip", type=lan_address, help="Send discovery to one known LAN IP")
    args = parser.parse_args()

    # TinyTuya 1.20 loads ./devices.json even when tuyadevices=[] is supplied.
    # An empty working directory prevents loading keys from the caller's cwd.
    # No DPS are polled and no address-range force scan is performed.
    if args.ip:
        found = discover_target(args.ip, args.seconds)
    else:
        with TemporaryDirectory(prefix="eh-lighting-discovery-") as working_directory:
            with chdir(working_directory):
                found = scanner.devices(
                    verbose=False, scantime=args.seconds, color=False, poll=False,
                    forcescan=False, tuyadevices=[], show_timer=False,
                )
    devices = [
        {
            "ip": info.get("ip", address),
            "device_id": info.get("gwId"),
            "version": info.get("version"),
            "product_key": info.get("productKey"),
        }
        for address, info in found.items()
    ]
    directory = Path(__file__).resolve().parents[2] / "storage/app/private/lighting"
    directory.mkdir(parents=True, exist_ok=True, mode=0o700)
    output = directory / "discovery.json"
    with NamedTemporaryFile(mode="w", encoding="utf-8", dir=directory, delete=False) as handle:
        json.dump({
            "captured_at": datetime.now(timezone.utc).isoformat(),
            "tinytuya_version": __version__, "devices": devices,
        }, handle, indent=2)
        temporary = handle.name
    os.replace(temporary, output)
    print(json.dumps({
        "count": len(devices),
        "devices": [{"ip": item["ip"], "version": item["version"],
                     "id_suffix": str(item["device_id"] or "")[-6:]} for item in devices],
        "saved_to": "storage/app/private/lighting/discovery.json",
        "device_state_polled": False,
        "lighting_changed": False,
    }, indent=2))


if __name__ == "__main__":
    main()
