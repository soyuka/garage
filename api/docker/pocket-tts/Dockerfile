# Kyutai pocket-tts as a resident TTS server, one language per container.
#
# CPU-ONLY ON PURPOSE. pocket-tts reports no speedup from a GPU, and the default `pip install torch`
# pulls the CUDA wheel — ~2.5 GB of libraries that would never be executed. The cpu index below is
# what keeps this image a few hundred megabytes instead of several gigabytes.
FROM python:3.12-slim

# curl for the healthcheck; nothing else is needed at runtime.
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

RUN pip install --no-cache-dir --index-url https://download.pytorch.org/whl/cpu torch \
    && pip install --no-cache-dir pocket-tts

# Weights are pulled from HuggingFace on first boot and cached in a named volume, so a rebuild does
# not re-download them. french_24l is ~760 MB.
ENV HF_HOME=/models

# `--language` binds ONE model for the life of the process; `--quantize` is int8 and measured ~3x
# faster than the cold unquantized path on a CPU-only host (docs/voice.md).
ENV POCKET_TTS_LANGUAGE=french_24l

EXPOSE 8000

CMD ["sh", "-c", "pocket-tts serve --host 0.0.0.0 --port 8000 --language ${POCKET_TTS_LANGUAGE} --quantize"]
