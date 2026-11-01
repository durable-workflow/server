"""Small-host benchmark boundaries (Server #325).

The committed summary must be a public allowlist that never republishes
private run metadata, and the target and driver scripts must accept only a
private target address on port 8080.
"""

import json
import os
import pathlib
import subprocess
import sys
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[3]
SUMMARIZER = ROOT / "scripts/perf/summarize-server-port-small-host.py"
DRIVER = ROOT / "scripts/perf/run-server-port-small-host.sh"
TARGET = ROOT / "scripts/perf/run-server-port-small-host-target.sh"
PROFILE = ROOT / "benchmarks/server-port/small-host/profile.json"

SENTINELS = (
    "/home/synthetic-operator",
    "10.99.0.2",
    "synthetic-private-ntp",
    "10.99.0.1",
    "sentinel-server-id-7f3a",
    "dw-small-sentinel-project",
    "offered-standard-sentinel",
    "sentinel-worker-id",
)


def write(path, text):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text)


def synthetic_run(root):
    driver, target = root / "driver", root / "target"
    machine = {"kernel": "6.8.0", "logical_cpus": 1, "physical_memory_bytes": 1 << 30, "cpu_model": "AMD EPYC",
               "docker": "27.5.1", "cgroup_driver": "systemd"}
    write(driver / "identity.json", json.dumps({
        "role": "driver", "compose_project": "dw-small-sentinel-project-driver",
        "profile": "/home/synthetic-operator/server/benchmarks/server-port/small-host/profile.json",
        "profile_sha256": "a" * 64, "source_revision": "b" * 40, "target_url": "http://10.99.0.2:8080",
        "target_server": {"version": "3.0.0-rc.2", "server_id": "sentinel-server-id-7f3a", "implementation": "rust"},
        "smoke": False, "provider_plan": "vhp-4c-8gb-amd", "machine": machine,
    }))
    write(target / "identity.json", json.dumps({
        "role": "target", "mode": "native-mysql", "compose_project": "dw-small-sentinel-project-target",
        "bind_address": "10.99.0.2", "profile_sha256": "a" * 64,
        "server_image": "ghcr.io/durable-workflow/server-native@sha256:" + "c" * 64, "database": "mysql",
        "container_limits": "none; the plan is the limit", "provider_plan": "vhp-1c-1gb-amd",
        "machine": {**machine, "swap_bytes": 0},
    }))
    clock = ("unix_seconds=1.0\nNTP=yes\nNTPSynchronized=yes\n"
             "Reference ID    : 0A630001 (synthetic-private-ntp)\n"
             "System time     : 0.000250000 seconds fast of NTP time\n"
             "       Server: 10.99.0.1 (synthetic-private-ntp)\n  Offset: -120us\n")
    for name in ("clock-start.txt", "clock-end.txt"):
        write(driver / name, clock)
    for name in ("clock-up.txt", "clock-down.txt"):
        write(target / name, clock)
    write(driver / "windows.tsv", "100.0\trate-4\tstart\n160.0\trate-4\tend\n170.0\tidle-6-1\tstart\n181.0\tidle-6-1\tend\n")
    write(driver / "ladder.tsv",
          "name\trate_per_second\tprobes\tsdk_workers\tverdict\tplanned\tclosed_in_window\tmax_probe_p95_seconds\treasons\n"
          "rate-4\t4\t1\t2\tpass\t240\t240\t0.41\t\n")
    write(driver / "rate-4-probe-1.json", json.dumps({
        "planned_starts": 240, "accepted_starts": 240, "completed_by_observation_deadline": 240,
        "closed_during_offer_window": 240, "latency_p50_seconds": 0.28, "latency_p95_seconds": 0.41,
        "latency_p99_seconds": 0.6, "errors": ["offered-standard-sentinel01: start: refused by http://10.99.0.2:8080"],
    }) + "\n")
    write(driver / "idle-6-1.json", json.dumps({
        "requested_polls": 6, "requested_poll_timeout_seconds": 10, "outcomes": {"empty": 6},
        "results": [{"worker_id": "sentinel-worker-id", "url": "http://10.99.0.2:8080"}],
        "cleanup_errors": ["sentinel-worker-id cleanup failed"], "all_registered": True,
    }) + "\n")
    write(driver / "capacity.json", json.dumps({"sustained_rate_per_second": 4}))
    write(driver / "health.log", "120.0 health 200 0.004\n120.0 ready 200 0.005\n")
    write(driver / "target-cluster-info.json", json.dumps({"server_id": "sentinel-server-id-7f3a"}))
    header = ("unix_seconds\tcpu_total_jiffies\tcpu_idle_jiffies\tcpu_iowait_jiffies\tcpu_steal_jiffies\tmem_total_kib"
              "\tmem_available_kib\tswap_total_kib\tswap_free_kib\tpswpin\tpswpout\toom_kill\tpsi_cpu_some_us"
              "\tpsi_memory_some_us\tpsi_memory_full_us\tpsi_io_some_us\n")
    rows = "".join(f"{t}\t{t * 100}\t{t * 40}\t0\t0\t1000000\t500000\t0\t0\t0\t0\t0\t0\t0\t0\t0\n" for t in range(100, 182, 10))
    write(target / "host-samples.tsv", header + rows)
    write(target / "container-samples.tsv", "100.0\tnative-mysql\t1000000\t8388608\n160.0\tnative-mysql\t13000000\t9437184\n")
    write(target / "container-states.txt", "native-mysql restarts=0 oom=false exit=0 status=running\n")
    write(target / "database-audit.tsv", "runs\tcompleted\t240\n")
    write(target / "teardown.txt", "containers=0 volumes=0\n")
    write(target / "server-logs.txt", "listening on 10.99.0.2 for dw-small-sentinel-project\n")
    return driver, target


class PublicSummaryTest(unittest.TestCase):
    def test_summary_keeps_results_but_no_private_run_metadata(self):
        with tempfile.TemporaryDirectory() as directory:
            driver, target = synthetic_run(pathlib.Path(directory))
            output = subprocess.run([sys.executable, "-I", str(SUMMARIZER), str(driver), str(target)],
                                    check=True, capture_output=True, text=True).stdout
        for sentinel in SENTINELS:
            self.assertNotIn(sentinel, output)
        summary = json.loads(output)
        self.assertEqual(summary["capacity"]["sustained_rate_per_second"], 4)
        self.assertEqual(summary["driver"]["target_server"], {"version": "3.0.0-rc.2", "implementation": "rust"})
        self.assertEqual(summary["target"]["provider_plan"], "vhp-1c-1gb-amd")
        self.assertTrue(summary["clock"]["driver"]["ntp_synchronized_at_both_ends"])
        self.assertAlmostEqual(summary["clock"]["target"]["max_abs_offset_seconds"], 0.00025)
        self.assertEqual(summary["steps"][0]["errors"], 1)
        self.assertEqual(summary["idle"][0]["probe"]["cleanup_errors"], 1)
        self.assertEqual(summary["target_teardown"], "containers=0 volumes=0")


class TargetAddressTest(unittest.TestCase):
    def run_script(self, arguments, environment):
        env = {key: value for key, value in os.environ.items() if not key.startswith("DW_")}
        env.update(environment)
        return subprocess.run(["bash", *arguments], capture_output=True, text=True, env=env, timeout=60)

    def test_driver_accepts_only_a_private_address_on_8080(self):
        with tempfile.TemporaryDirectory() as directory:
            env = {"DW_PORT_SOURCE_ROOT": directory, "DW_PORT_SDK_ROOT": directory}
            for url in ("http://203.0.113.10:8080", "http://10.99.0.2:8080;id;#", "http://0.0.0.0:8080",
                        "http://10.0.0.5:9000", "http://127.0.0.1:8080", "http://10.0.0.256:8080",
                        "http://172.32.0.1:8080", "http://10.0.0.5:8080/api"):
                result = self.run_script([str(DRIVER), str(PROFILE), f"{directory}/out", url], env)
                self.assertEqual(result.returncode, 2, url)
                self.assertIn("private (RFC 1918)", result.stderr, url)
            # A private address passes the check; this empty source tree then
            # stops at the profile's input hashes, before any Docker call.
            for url in ("http://10.99.0.2:8080", "http://172.16.4.2:8080", "http://192.168.1.4:8080"):
                result = self.run_script([str(DRIVER), str(PROFILE), f"{directory}/out-{url[7:9]}", url], env)
                self.assertEqual(result.returncode, 1, url)
                self.assertNotIn("private (RFC 1918)", result.stderr, url)
                self.assertIn("capacity_adapter.php", result.stderr, url)

    def test_target_accepts_only_a_private_bind_address(self):
        with tempfile.TemporaryDirectory() as directory:
            for bind in ("0.0.0.0", "203.0.113.10", "127.0.0.1", "10.0.0.5;id", "172.32.0.1", ""):
                result = self.run_script([str(TARGET), "up", str(PROFILE), f"{directory}/out", "native-sqlite"],
                                         {"DW_SMALL_TARGET_BIND": bind})
                self.assertEqual(result.returncode, 2 if bind else 1, bind)
            # Loopback only in an explicit stand-in; a profile without image
            # digests then stops before any Docker call.
            profile = pathlib.Path(directory) / "profile.json"
            profile.write_text(json.dumps({"artifacts": {"native_image": "x", "php_server": "y"}}))
            result = self.run_script([str(TARGET), "up", str(profile), f"{directory}/standin", "native-sqlite"],
                                     {"DW_SMALL_TARGET_BIND": "127.0.0.1", "DW_SMALL_STAND_IN": "1"})
            self.assertEqual(result.returncode, 1)
            self.assertIn("by digest", result.stderr)

    def test_observer_receives_the_endpoint_as_data(self):
        source = DRIVER.read_text()
        start = source.index('docker run -d --name "$project-health"')
        observer = source[start:source.index("> /dev/null", start)]
        self.assertIn('-e OBSERVED_URL="$target"', observer)
        self.assertIn('"$OBSERVED_URL/api/$path"', observer)
        # The program is single-quoted, so only the -e value names $target.
        self.assertEqual(observer.count("$target"), 1)


if __name__ == "__main__":
    unittest.main()
