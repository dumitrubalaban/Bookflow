/** @type {import('tailwindcss').Config} */
export default {
    // The host theme (Astra) ships button/link color rules with their own
    // !important, so even a maximally-specific selector still loses to
    // them — only matching !important with !important actually wins.
    // `true` appends !important to every utility declaration.
    important: true,
};
