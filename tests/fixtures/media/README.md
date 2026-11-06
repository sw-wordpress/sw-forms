Synthetic media samples (0.3 seconds of a 440 Hz sine wave; video adds a 32×24 red frame).
No personal data or external copyrighted recordings. Generated with FFmpeg; no FFmpeg runtime dependency.

Audio source: `ffmpeg -f lavfi -i sine=frequency=440:duration=0.3 audio.EXT`.
WebM/Ogg use `-c:a libopus`; M4A/MP4 use `-c:a aac`.
Video adds `-f lavfi -i color=c=red:s=32x24:d=0.3` before the audio source,
then `-c:v libvpx -c:a libopus -shortest video.webm` or
`-c:v libx264 -c:a aac -shortest video.mp4`.

The HTTP tests submit the committed bytes as real multipart uploads. They verify
MIME/stream matching, preservation, metadata and ownership cleanup. Browser tests
also submit real MediaRecorder output using Chromium's synthetic hardware.
