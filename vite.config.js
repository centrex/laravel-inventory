import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";

export default defineConfig({
    plugins: [
        laravel({
            input: ["resources/js/app.js"],
            hotFile: "public/inventory.hot",
            refresh: [
                "resources/views/**",
                "src/**",
                "routes/**",
                "config/**",
                "workbench/**",
                "tests/**",
            ],
        }),
    ],
    server: {
        host: "127.0.0.1",
        port: 5175,
        strictPort: true,
        cors: true,
    },
});
