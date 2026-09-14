'use strict';

/*
 * Loads the browser chess engine (chessutils.js + validation.js + isCheckMate.js)
 * into a Node vm context so the exact code the browser runs can be unit-tested.
 *
 * The engine files are plain legacy ad-hoc scripts: they declare functions and
 * globals (`board`, `chessHistory`, `numMoves`, ...) at the top level without
 * module exports. The web app supplies those globals inline via gui.php
 * (constants PAWN..COLOR_MASK, CURPIECE..PROMOTEDTO, board array, history).
 * This loader supplies the same prologue and runs the files in a shared
 * non-strict context, so every declaration lands on the context object itself.
 */

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const JAVASCRIPT_DIR = path.join(__dirname, '..', '..', 'javascript');

/* Mirrors what gui.php emits before the engine files load on a real page. */
const PROLOGUE = `
var PAWN = 1, KNIGHT = 2, BISHOP = 4, ROOK = 8, QUEEN = 16, KING = 32;
var BLACK = 128, WHITE = 0, COLOR_MASK = 127;
var CURPIECE = 0, CURCOLOR = 1, FROMROW = 2, FROMCOL = 3, TOROW = 4, TOCOL = 5, PROMOTEDTO = 6;
var DEBUG = false;
var board = [];
for (var __i = 0; __i < 8; __i++) { board[__i] = [0, 0, 0, 0, 0, 0, 0, 0]; }
var numMoves = -1, chessHistory = [], errMsg = '', curColor = 'white';
var i18nMessages = {};
var alert = function () {};
`;

function readScript(name) {
  return fs.readFileSync(path.join(JAVASCRIPT_DIR, name), 'utf8');
}

let engine = null;

function loadEngine() {
  if (engine) {
    return engine;
  }

  const context = vm.createContext({});
  const sources = [
    PROLOGUE,
    readScript('chessutils.js'),
    readScript('validation.js'),
    readScript('isCheckMate.js'),
  ];
  vm.runInContext(sources.join('\n'), context, { filename: 'webchess-engine' });
  engine = context;
  return engine;
}

module.exports = { loadEngine };