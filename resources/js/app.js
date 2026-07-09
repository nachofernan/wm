import './bootstrap';
import Alpine from 'alpinejs';
import { game } from './game.js';

window.Alpine = Alpine;
Alpine.data('game', game);
Alpine.start();
