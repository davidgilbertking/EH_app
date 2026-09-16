import axios from 'axios';
import { reactive } from 'vue';
import { createLightingClient, createLightingState } from './api.js';

export const lighting = createLightingClient({
    http: axios,
    state: reactive(createLightingState()),
});
