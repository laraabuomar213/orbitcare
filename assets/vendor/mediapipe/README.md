# MediaPipe Tasks Vision (self-hosted, offline)

Used by the Alertness Check to find the eyes in the camera image **on the device**.
Nothing from the camera leaves the browser; only two numbers (blinks per minute, PERCLOS %)
are saved with a test.

| File | Source |
|---|---|
| `vision_bundle.mjs`, `wasm/*` | npm package `@mediapipe/tasks-vision@0.10.14` (cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.14/) |
| `face_landmarker.task` | storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task |

License: Apache License 2.0 (Google MediaPipe).
Downloaded once on 2026-11-12 so the app works without internet.
