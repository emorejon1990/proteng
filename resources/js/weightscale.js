'use strict';

let port;
let reader;
let inputDone;
let outputDone;
let inputStream;
let outputStream;
let buffer = '';

const log = document.getElementById('log');
const ledCBs = document.querySelectorAll('input.led');
const divLeftBut = document.getElementById('leftBut');
const divRightBut = document.getElementById('rightBut');
const butConnect = document.getElementById('butConnect');

const GRID_HAPPY = [1,0,0,0,1,0,0,0,0,0,0,0,1,0,0,1,0,0,0,1,0,1,1,1,0];
const GRID_SAD =   [1,0,0,0,1,0,0,0,0,0,0,0,1,0,0,0,1,1,1,0,1,0,0,0,1];
const GRID_OFF =   [0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0];
const GRID_HEART = [0,1,0,1,0,1,1,1,1,1,1,1,1,1,1,0,1,1,1,0,0,0,1,0,0];
console.log("carga"); // Debugging message

// document.addEventListener('DOMContentLoaded', () => {
//     console.log("WeightScale.js initialization script is running"); // Debugging message
//   butConnect.addEventListener('click', clickConnect);

//   // CODELAB: Add feature detection here.
//   const notSupported = document.getElementById('notSupported');
//   notSupported.classList.toggle('hidden', 'serial' in navigator);

// });

function waitForElement(selector, callback) {
    const el = document.querySelector(selector);
    if (el) return callback(el);
    const observer = new MutationObserver(() => {
        const el = document.querySelector(selector);
        if (el) {
            observer.disconnect();
            callback(el);
        }
    });
    observer.observe(document.body, { childList: true, subtree: true });
}

// Esperar a que el botón esté presente
waitForElement('#butConnect', (el) => {
    console.log('Botón encontrado, inicializando listener');
    el.addEventListener('click', clickConnect);
});


/**
 * @name connect
 * Opens a Web Serial connection to a micro:bit and sets up the input and
 * output stream.
 */
async function connect() {
  // CODELAB: Add code to request & open port here.
    // - Request a port and open a connection.
    port = await navigator.serial.requestPort();
    // - Wait for the port to open.
    await port.open({ baudRate: 9600 });
  // CODELAB: Add code setup the output stream here.

  // CODELAB: Send CTRL-C and turn off echo on REPL

  // CODELAB: Add code to read the stream here.
  let decoder = new TextDecoderStream();
inputDone = port.readable.pipeTo(decoder.writable);
inputStream = decoder.readable;

reader = inputStream.getReader();
readLoop();

}


/**
 * @name disconnect
 * Closes the Web Serial connection.
 */
async function disconnect() {
//   drawGrid(GRID_OFF);
//   sendGrid();

  // CODELAB: Close the input stream (reader).
    if (reader) {
        await reader.cancel();
        await inputDone.catch(() => {});
        reader = null;
        inputDone = null;
    }
  // CODELAB: Close the output stream.
    if (outputStream) {
        await outputStream.getWriter().close();
        await outputDone;
        outputStream = null;
        outputDone = null;
    }
  // CODELAB: Close the port.
    await port.close();
    port = null;

}


/**
 * @name clickConnect
 * Click handler for the connect/disconnect button.
 */
async function clickConnect() {
  // CODELAB: Add disconnect code here.
    if (port) {
        await disconnect();
        toggleUIConnected(false);
        return;
    }
    console.log("click");
  // CODELAB: Add connect code here.
  await connect();
  // CODELAB: Reset the grid on connect here.

  // CODELAB: Initialize micro:bit buttons.

    toggleUIConnected(true);
}


/**
 * @name readLoop
 * Reads data from the input stream and displays it on screen.
 */
async function readLoop() {
    buffer = '';

    while (true) {
        const { value, done } = await reader.read();

        if (value) {
            buffer += value;

            // Si la balanza envía datos terminados en '\n' o '\r'
            if (value.includes('\n') || value.includes('\r')) {
                const cleaned = buffer.trim(); // elimina \r \n y espacios

                console.log('Peso completo recibido:', cleaned);

                const peso = parseFloat(cleaned);
                if (!isNaN(peso)) {
                    const input = document.querySelector('#product-weight');
                    if (input) {
                        input.value = peso;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }

                // Reiniciar el buffer para el siguiente valor
                buffer = '';
            }
        }

        if (done) {
            console.log('[readLoop] DONE');
            reader.releaseLock();
            break;
        }
    }
}


/**
 * @name sendGrid
 * Iterates over the checkboxes and generates the command to set the LEDs.
 */
function sendGrid() {
  // CODELAB: Generate the grid

}


/**
 * @name writeToStream
 * Gets a writer from the output stream and send the lines to the micro:bit.
 * @param  {...string} lines lines to send to the micro:bit
 */
function writeToStream(...lines) {
  // CODELAB: Write to output stream

}


/**
 * @name watchButton
 * Tells the micro:bit to print a string on the console on button press.
 * @param {String} btnId Button ID (either BTN1 or BTN2)
 */
function watchButton(btnId) {
  // CODELAB: Hook up the micro:bit buttons to print a string.

}


/**
 * @name LineBreakTransformer
 * TransformStream to parse the stream into lines.
 */
class LineBreakTransformer {
  constructor() {
    // A container for holding stream data until a new line.
    this.container = '';
  }

  transform(chunk, controller) {
    // CODELAB: Handle incoming chunk

  }

  flush(controller) {
    // CODELAB: Flush the stream.

  }
}


/**
 * @name JSONTransformer
 * TransformStream to parse the stream into a JSON object.
 */
class JSONTransformer {
  transform(chunk, controller) {
    // CODELAB: Attempt to parse JSON content

  }
}


/**
 * @name buttonPushed
 * Event handler called when one of the micro:bit buttons is pushed.
 * @param {Object} butEvt
 */
function buttonPushed(butEvt) {
  // CODELAB: micro:bit button press handler

}


/**
 * The code below is mostly UI code and is provided to simplify the codelab.
 */

function initCheckboxes() {
  ledCBs.forEach((cb) => {
    cb.addEventListener('change', () => {
      sendGrid();
    });
  });
}

function drawGrid(grid) {
  if (grid) {
    grid.forEach((v, i) => {
      ledCBs[i].checked = !!v;
    });
  }
}

function toggleUIConnected(connected) {
  let lbl = 'Connect';
  if (connected) {
    lbl = 'Disconnect';
  }
  butConnect.textContent = lbl;
  ledCBs.forEach((cb) => {
    if (connected) {
      cb.removeAttribute('disabled');
      return;
    }
    cb.setAttribute('disabled', true);
  });
}
