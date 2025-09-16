import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from "@tailwindcss/vite";
import fs from 'fs';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        cors: true,
        // https: {
        //     key: fs.readFileSync('./certs/localhost.key'),
        //     cert: fs.readFileSync('./certs/localhost.crt'),
        // },
        // host: '192.168.12.184',
        // port: 5173,
    },
});
