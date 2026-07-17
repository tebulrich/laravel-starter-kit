<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { apiGet } from '@/api/client';

type HealthPayload = {
  status: string;
  checks?: Record<string, { ok: boolean; detail: string }>;
};

const loading = ref(true);
const error = ref<string | null>(null);
const health = ref<HealthPayload | null>(null);

onMounted(async () => {
  try {
    health.value = await apiGet<HealthPayload>('/api/health');
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Health check failed';
  } finally {
    loading.value = false;
  }
});
</script>

<template>
  <section class="space-y-3">
    <h2 class="text-xl font-medium">API health</h2>
    <p v-if="loading === true" class="text-slate-400">Loading…</p>
    <p v-else-if="error !== null" class="text-rose-300">{{ error }}</p>
    <pre
      v-else
      class="overflow-x-auto rounded-lg border border-slate-800 bg-slate-900 p-4 text-sm text-slate-200"
    >{{ health }}</pre>
  </section>
</template>
