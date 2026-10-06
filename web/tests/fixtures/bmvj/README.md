# BMVJ test payload fixture

`input_tester.flash` is the 168-byte PAD TEST payload from the separate Net de
Get Disassembly task. SHA-256:

```text
e1f4e3c297448b4308eb45efc8107522ec0716d2961c5b1bdc5a9d22bc56881b
```

The binary header identifies `G001`, title `PAD TEST`, and one 8 KiB block.
The associated catalog fixture uses the misc category, zero level thresholds,
type 1 (full minigame), and filename `0000.G001.cgb`.

This is a raw flash image, not the full Net de Get HTTP download wrapper. Use it
to validate catalog serialization and byte-preserving local transport only
when seeded into an isolated test database. Do not advertise it from the live
catalog until a wrapper is produced and host download/write/launch is verified.
