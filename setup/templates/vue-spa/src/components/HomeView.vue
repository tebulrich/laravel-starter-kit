<script setup lang="ts">
const apiBase = import.meta.env.VITE_API_BASE_URL || '(relative /api via Vite proxy)';
</script>

<template>
  <section class="space-y-3">
    <h2 class="text-xl font-medium">Vue SPA is ready</h2>
    <p class="text-slate-300">
      Stack: Vue 3 + Vite + TypeScript + Tailwind. Talks to the Laravel API at
      <code class="rounded bg-slate-900 px-1.5 py-0.5 text-slate-100">{{ apiBase }}</code>.
    </p>
    <p class="text-slate-400 text-sm">
      Default API URL from setup: <code>{{API_BASE_URL}}</code>
    </p>
  </section>
</template>
