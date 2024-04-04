// Static loading of ES6 Modules. But we can't trust drupal/browser of loading this one first (even if we set it as a dependency
// So leaving it as Unused for now, since i don't want to read 4 hours of JS help issues again.
import * as pdfjsLib from "https://cdn.jsdelivr.net/npm/pdfjs-dist@4.0.379/build/pdf.min.mjs";
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.0.379/build/pdf.worker.min.mjs';
window['pdfjsLib'] = pdfjsLib;
