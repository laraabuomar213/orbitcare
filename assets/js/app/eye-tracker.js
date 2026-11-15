// Eye signs from the camera, computed on this device with MediaPipe Face Landmarker
// (self-hosted in assets/vendor/mediapipe — works offline). No image or video is stored or sent:
// the only output is a summary (seconds with a visible face, number of blinks, seconds with the
// eyes at least 80 % closed).
//
// Eye opening = eye aspect ratio (EAR) from six landmarks per eye. Closure is relative to the
// person's OWN open-eye level in the same session (90th percentile of recent EAR values), so it
// works for different eye shapes and camera distances:  closure = 1 − EAR ÷ open-eye EAR.
//   PERCLOS (P80): share of face time with closure ≥ 80 %.
//   Blink: closure ≥ 50 % that ends within 500 ms (longer closures are not counted as blinks).
// These are estimates from a webcam, not a medical measurement.
import { appUrl } from './api.js';

const VENDOR = appUrl('assets/vendor/mediapipe/');
// MediaPipe face mesh indices: [corner, top, top, corner, bottom, bottom]
const LEFT_EYE = [362, 385, 387, 263, 373, 380];
const RIGHT_EYE = [33, 160, 158, 133, 153, 144];
const FRAME_INTERVAL_MS = 66;        // ~15 analysed frames per second keeps the test responsive
const MAX_FRAME_GAP_MS = 250;        // longer gaps (busy device) are not counted as measured time
const REFERENCE_SAMPLES = 300;       // ~20 s of open-eye reference

let landmarkerPromise = null;        // the model is loaded once per page

function loadLandmarker() {
    landmarkerPromise ??= (async () => {
        const vision = await import(`${VENDOR}vision_bundle.mjs`);
        const files = await vision.FilesetResolver.forVisionTasks(`${VENDOR}wasm`);
        return vision.FaceLandmarker.createFromOptions(files, {
            baseOptions: { modelAssetPath: `${VENDOR}face_landmarker.task`, delegate: 'CPU' },
            runningMode: 'VIDEO',
            numFaces: 1,
        });
    })();
    return landmarkerPromise;
}

function distance(a, b) {
    return Math.hypot(a.x - b.x, a.y - b.y);
}

function eyeAspectRatio(points, [p1, p2, p3, p4, p5, p6]) {
    const width = distance(points[p1], points[p4]);
    return width ? (distance(points[p2], points[p6]) + distance(points[p3], points[p5])) / (2 * width) : null;
}

export class EyeTracker {
    /**
     * @param {HTMLVideoElement} video
     * @param {{perclos_closure: number, blink_closure: number, blink_max_ms: number}} rules from config/alertness.php
     * @param {(update: object) => void} onUpdate called with { state, blinksPerMinute, perclos, faceSeconds }
     */
    constructor(video, rules, onUpdate) {
        this.video = video;
        this.rules = rules;
        this.onUpdate = onUpdate;
        this.stream = null;
        this.timer = null;
        this.references = [];
        this.openLevel = null;
        this.resetCounts();
    }

    resetCounts() {
        this.faceSeconds = 0;
        this.closedSeconds = 0;
        this.blinks = 0;
        this.closingSince = null;
        this.lastFrame = null;
    }

    async start() {
        this.onUpdate({ state: 'starting' });
        this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false });
        this.video.srcObject = this.stream;
        await this.video.play();
        this.onUpdate({ state: 'loading' });
        this.landmarker = await loadLandmarker();
        if (!this.stream) return;            // stopped while the model was loading
        this.onUpdate({ state: 'searching' });
        this.loop();
    }

    stop() {
        clearTimeout(this.timer);
        this.timer = null;
        this.stream?.getTracks().forEach((track) => track.stop());
        this.stream = null;
        this.video.srcObject = null;
        this.recording = false;
        this.onUpdate({ state: 'off' });
    }

    startRecording() {
        this.resetCounts();
        this.recording = true;
    }

    // Summary sent with the test result (numbers only).
    stopRecording() {
        this.recording = false;
        return {
            face_seconds: Math.round(this.faceSeconds * 10) / 10,
            blinks: this.blinks,
            closed_seconds: Math.round(Math.min(this.closedSeconds, this.faceSeconds) * 100) / 100,
        };
    }

    loop() {
        if (!this.stream) return;
        try {
            if (this.video.readyState >= 2) this.analyse(performance.now());
        } catch (error) {
            this.onUpdate({ state: 'error' });
            return;
        }
        this.timer = setTimeout(() => this.loop(), FRAME_INTERVAL_MS);
    }

    analyse(now) {
        const result = this.landmarker.detectForVideo(this.video, now);
        const points = result.faceLandmarks?.[0];
        const gap = this.lastFrame === null ? 0 : now - this.lastFrame;
        this.lastFrame = now;

        if (!points) {
            this.closingSince = null;
            this.onUpdate({ state: 'no_face', ...this.liveValues() });
            return;
        }
        const left = eyeAspectRatio(points, LEFT_EYE);
        const right = eyeAspectRatio(points, RIGHT_EYE);
        if (left === null || right === null) return;
        const ear = (left + right) / 2;

        this.references.push(ear);
        if (this.references.length > REFERENCE_SAMPLES) this.references.shift();
        if (this.references.length >= 30 && this.references.length % 15 === 0) {
            const sorted = [...this.references].sort((a, b) => a - b);
            this.openLevel = sorted[Math.floor(sorted.length * 0.9)];
        }
        if (!this.openLevel) {
            this.onUpdate({ state: 'calibrating' });
            return;
        }

        const closure = Math.min(1, Math.max(0, 1 - ear / this.openLevel));
        if (this.recording && gap > 0 && gap <= MAX_FRAME_GAP_MS) {
            const seconds = gap / 1000;
            this.faceSeconds += seconds;
            if (closure >= this.rules.perclos_closure) this.closedSeconds += seconds;
        }

        // Blink: eyes close (≥ 50 %) and open again within the blink limit.
        if (closure >= this.rules.blink_closure) {
            this.closingSince ??= now;
        } else if (this.closingSince !== null) {
            if (this.recording && now - this.closingSince <= this.rules.blink_max_ms) this.blinks++;
            this.closingSince = null;
        }
        this.onUpdate({ state: 'tracking', ...this.liveValues() });
    }

    liveValues() {
        if (!this.recording || this.faceSeconds < 10) return { blinksPerMinute: null, perclos: null, faceSeconds: this.faceSeconds };
        return {
            blinksPerMinute: this.blinks / (this.faceSeconds / 60),
            perclos: (this.closedSeconds / this.faceSeconds) * 100,
            faceSeconds: this.faceSeconds,
        };
    }
}
