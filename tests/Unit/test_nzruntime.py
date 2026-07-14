import importlib.util
import json
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("nzruntime", ROOT / "nzruntime.py")
nzruntime = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(nzruntime)


def process(name, status="online", restarts=0, unstable=0):
    return {
        "name": name,
        "pm2_env": {
            "status": status,
            "restart_time": restarts,
            "unstable_restarts": unstable,
        },
    }


class RuntimeTopologyTest(unittest.TestCase):
    def healthy(self, queue_restarts=66, web_restarts=0):
        return [
            process("nezha-web", restarts=web_restarts),
            process("nezha-queue", restarts=queue_restarts),
            process("nezha-queue-2", restarts=queue_restarts),
            process("nezha-staging-web", restarts=10),
        ]

    def test_health_uses_the_three_production_processes(self):
        code, lines = nzruntime.health_report(self.healthy())
        self.assertEqual(code, 0)
        self.assertEqual(len(lines), 3)
        self.assertNotIn("staging", "\n".join(lines))

    def test_health_fails_when_a_production_process_is_missing_or_offline(self):
        code, lines = nzruntime.health_report([
            process("nezha-web", status="stopped"),
            process("nezha-queue"),
        ])
        self.assertEqual(code, 2)
        self.assertIn("nezha-queue-2: missing", "\n".join(lines))

    def test_daily_treats_stable_queue_recycling_as_information(self):
        previous = {
            "processes": {
                "nezha-web": {"status": "online", "restarts": 0, "unstable": 0},
                "nezha-queue": {"status": "online", "restarts": 65, "unstable": 0},
                "nezha-queue-2": {"status": "online", "restarts": 65, "unstable": 0},
            }
        }
        code, lines, _ = nzruntime.daily_report(self.healthy(), previous)
        self.assertEqual(code, 0)
        self.assertIn("planned-capable queue recycle", "\n".join(lines))

    def test_daily_warns_on_web_or_unstable_restart(self):
        previous = {
            "processes": {
                "nezha-web": {"status": "online", "restarts": 0, "unstable": 0},
                "nezha-queue": {"status": "online", "restarts": 66, "unstable": 0},
                "nezha-queue-2": {"status": "online", "restarts": 66, "unstable": 0},
            }
        }
        processes = self.healthy(web_restarts=1)
        processes[1]["pm2_env"]["unstable_restarts"] = 1
        code, lines, _ = nzruntime.daily_report(processes, previous)
        self.assertEqual(code, 1)
        report = "\n".join(lines)
        self.assertIn("nezha-web: restart increased", report)
        self.assertIn("unstable restart increased", report)

    def test_state_round_trip_uses_per_process_counters(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory) / "state.json"
            snapshots = {"nezha-web": {"status": "online", "restarts": 1, "unstable": 0}}
            nzruntime.write_state(state, snapshots)
            self.assertEqual(nzruntime.read_state(state)["processes"], snapshots)
            json.loads(state.read_text(encoding="utf-8"))

    def test_fpm_parser_reads_the_requested_pool(self):
        with tempfile.TemporaryDirectory() as directory:
            config = Path(directory) / "php-fpm.conf"
            config.write_text(
                "[global]\nerror_log=/tmp/fpm.log\n"
                "[www]\npm = dynamic\npm.max_children = 12\n"
                "[staging]\npm.max_children = 4\n",
                encoding="utf-8",
            )
            self.assertEqual(nzruntime.fpm_max_children(config, "www"), 12)
            self.assertEqual(nzruntime.fpm_max_children(config, "staging"), 4)


if __name__ == "__main__":
    unittest.main()
