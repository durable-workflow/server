"""Checks a libbrotlidec for the CVE-2026-111497 fix without a malformed stream.

The fix adds BROTLI_DECODER_ERROR_FORMAT_BLOCK_SWITCH (-17), which an
unfixed decoder names INVALID. A valid stream must still decode.
Usage: brotli-check.py LIBRARY ENCODED_HEX EXPECTED_TEXT
"""
import ctypes
import sys

library, encoded, expected = sys.argv[1], bytes.fromhex(sys.argv[2]), sys.argv[3].encode()
decoder = ctypes.CDLL(library)
decoder.BrotliDecoderErrorString.restype = ctypes.c_char_p
decoder.BrotliDecoderErrorString.argtypes = [ctypes.c_int]
name = decoder.BrotliDecoderErrorString(-17)
if name != b"_ERROR_FORMAT_BLOCK_SWITCH":
    sys.exit(f"{library} reports error -17 as {name!r}: it lacks the CVE-2026-111497 fix.")

decoder.BrotliDecoderDecompress.restype = ctypes.c_int
decoder.BrotliDecoderDecompress.argtypes = [
    ctypes.c_size_t, ctypes.c_char_p, ctypes.POINTER(ctypes.c_size_t), ctypes.c_char_p]
size = ctypes.c_size_t(len(expected) * 4 + 64)
output = ctypes.create_string_buffer(size.value)
if decoder.BrotliDecoderDecompress(len(encoded), encoded, ctypes.byref(size), output) != 1:
    sys.exit(f"{library} cannot decode a valid stream.")
if output.raw[: size.value] != expected:
    sys.exit(f"{library} decoded a valid stream incorrectly.")
print(f"{library}: CVE-2026-111497 fix present; valid stream round trips")
