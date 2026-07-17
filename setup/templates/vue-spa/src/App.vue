<script setup lang="ts">
import { RouterLink, RouterView } from 'vue-router';
</script>

<template>
  <div class="mx-auto flex min-h-screen max-w-3xl flex-col gap-6 px-6 py-10">
    <header class="flex items-center justify-between gap-4 border-b border-slate-800 pb-4">
      <div>
        <p class="text-xs uppercase tracking-[0.2em] text-slate-400">Laravel Starter Kit</p>
        <h1 class="text-2xl font-semibold tracking-tight">{{APP_NAME}}</h1>
      </div>
      <nav class="flex gap-4 text-sm text-slate-300">
        <RouterLink class="hover:text-white" to="/">Home</RouterLink>
        <RouterLink class="hover:text-white" to="/health">API health</RouterLink>
      </nav>
    </header>
    <RouterView />
  </div>
</template>
