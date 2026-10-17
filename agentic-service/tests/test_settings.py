"""T2.8: config per agent, ASSESSOR_MODELS als lijst, standaarden uit DESIGN §10."""
import importlib.util
import os
import types
import unittest
from unittest import mock

from agentic.settings import DEFAULTS, from_env, is_cloud_model, load_settings, local_models, split_models

DOCKER_CONFIG = os.path.join(os.path.dirname(__file__), "..", "..", "docker", "agent-config.py")


def load_docker_config(env):
    spec = importlib.util.spec_from_file_location("docker_agent_config", DOCKER_CONFIG)
    module = importlib.util.module_from_spec(spec)
    with mock.patch.dict(os.environ, env, clear=True):
        spec.loader.exec_module(module)
    return module


class SettingsTest(unittest.TestCase):
    def test_split_models(self):
        self.assertEqual(split_models("a, b"), ["a", "b"])
        self.assertEqual(split_models("a b,,c"), ["a", "b", "c"])
        self.assertEqual(split_models([" a ", ""]), ["a"])

    def test_env_assessor_models_list(self):
        self.assertEqual(from_env({"AI_ASSESSOR_MODELS": "a, b"})["ASSESSOR_MODELS"], ["a", "b"])

    def test_env_defaults(self):
        values = from_env({})
        self.assertEqual(values["INTENT_MODEL"], "gpt-oss:20b-cloud")
        self.assertEqual(values["INTENT_THINK"], "low")
        self.assertEqual(values["ASSESSOR_THINK"], "medium")
        self.assertEqual(values["VALIDATOR_MODEL"], "gpt-oss:120b-cloud")
        self.assertEqual(values["VALIDATOR_THINK"], "high")
        self.assertEqual(values["DESIGNER_MODEL"], "gpt-oss:120b-cloud")
        self.assertEqual(values["OLLAMA_URL"], "http://localhost:11434/api/chat")
        self.assertEqual(values["POLL_INTERVAL"], 10)
        self.assertEqual(values["MAX_CHAT_TOKENS"], 20000)
        self.assertEqual(values["ASSESSOR_MODELS"], ["gpt-oss:20b-cloud"])

    def test_env_overrides(self):
        values = from_env({"AI_INTENT_MODEL": "qwen3:4b", "AI_INTENT_THINK": "", "AI_POLL_INTERVAL": "30"})
        self.assertEqual(values["INTENT_MODEL"], "qwen3:4b")
        self.assertEqual(values["INTENT_THINK"], "low")  # leeg = standaard
        self.assertEqual(values["POLL_INTERVAL"], 30)

    def test_docker_agent_config(self):
        module = load_docker_config({"AI_API_KEY": "k", "AI_BASE_URL": "http://x/api.php",
                                     "AI_ASSESSOR_MODELS": "a, b", "AI_VALIDATOR_THINK": "medium"})
        self.assertEqual(module.ASSESSOR_MODELS, ["a", "b"])
        self.assertEqual(module.VALIDATOR_THINK, "medium")
        self.assertEqual(module.API_KEY, "k")
        self.assertEqual(module.HELPER_MODEL, DEFAULTS["HELPER_MODEL"])

    def test_cloud_only(self):
        self.assertTrue(is_cloud_model("gpt-oss:20b-cloud"))
        self.assertTrue(is_cloud_model("kimi-k2.6:cloud"))
        self.assertFalse(is_cloud_model("qwen2.5:1.5b"))
        self.assertFalse(is_cloud_model("gpt-oss:20b"))
        self.assertTrue(from_env({})["CLOUD_ONLY"])
        self.assertEqual(local_models(from_env({})), [])  # standaardmodellen zijn allemaal cloud
        values = from_env({"AI_ASSESSOR_MODELS": "gpt-oss:20b-cloud, qwen3:4b", "AI_INTENT_MODEL": "gemma3:1b"})
        self.assertEqual(local_models(values), ["gemma3:1b", "qwen3:4b"])
        self.assertEqual(local_models(from_env({"AI_INTENT_MODEL": "gemma3:1b", "AI_CLOUD_ONLY": "0"})), [])
        self.assertFalse(load_settings(types.SimpleNamespace(CLOUD_ONLY=False)).CLOUD_ONLY)

    def test_load_settings_from_module(self):
        cfg = types.SimpleNamespace(API_KEY="k", ASSESSOR_MODELS="x y", POLL_INTERVAL=None)
        settings = load_settings(cfg)
        self.assertEqual(settings.ASSESSOR_MODELS, ["x", "y"])
        self.assertEqual(settings.POLL_INTERVAL, 10)
        self.assertEqual(settings.DESIGNER_THINK, "high")


if __name__ == "__main__":
    unittest.main()
