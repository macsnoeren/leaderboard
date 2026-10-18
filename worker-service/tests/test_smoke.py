"""De testopzet werkt en het pakket is importeerbaar."""
import unittest


class SmokeTest(unittest.TestCase):
    def test_import(self):
        import worker
        self.assertTrue(worker.VERSION)


if __name__ == "__main__":
    unittest.main()
