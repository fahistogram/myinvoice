<script setup lang="ts">
/**
 * Dashboard widget „co mi zbyde" (#68) — projektovaný čistý příjem pro OSVČ.
 * Self-gating: nic nevykreslí pro s.r.o. / plátce DPH / bez dat. Počítá přes
 * sdílený engine (useTaxEngine) na projektovaném příjmu běžícího roku.
 */
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import { taxApi, type TaxAnalysis } from '@/api/tax'
import { regular, type EngineProfile } from '@/composables/useTaxEngine'
import { formatMoney } from '@/composables/useFormat'

const { t } = useI18n()
const supplierStore = useSupplierStore()
const isOsvc = computed(() => supplierStore.currentSupplier?.taxpayer_type === 'fo')

const analysis = ref<TaxAnalysis | null>(null)

onMounted(async () => {
  if (!isOsvc.value) return
  try {
    analysis.value = await taxApi.analysis(new Date().getFullYear())
  } catch { /* widget je best-effort, chyby tiše ignoruj */ }
})

const calc = computed(() => {
  const a = analysis.value
  if (!a) return null
  // Běžící rok → projektovaný příjem z run-rate; uzavřený → skutečný.
  const income = a.mode === 'forecast'
    ? Math.round((a as any).predict?.projected ?? a.ytd_income ?? 0)
    : Math.round(a.income ?? 0)
  if (income <= 0) return null
  const prof: EngineProfile = { ...a.profile, is_vat_payer: a.is_vat_payer }
  const r = regular(prof, income, a.constants)
  return {
    year: a.year, projected: a.mode === 'forecast',
    income, net: Math.round(r.net), eff: r.eff, total: Math.round(r.total),
  }
})
</script>

<template>
  <RouterLink v-if="isOsvc && calc" to="/tax"
    class="block bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm hover:border-primary-300 transition">
    <div class="flex items-center justify-between mb-2">
      <span class="text-xs uppercase tracking-wide text-neutral-500 font-medium">
        {{ t('dashboard.tax_net_title', { year: calc.year }) }}<template v-if="calc.projected"> · {{ t('dashboard.tax_net_projected') }}</template>
      </span>
      <span class="text-xs text-primary-600">{{ t('dashboard.tax_net_open') }} →</span>
    </div>
    <div class="flex flex-wrap items-end gap-x-6 gap-y-2">
      <div>
        <div class="text-2xl font-bold font-mono text-success-700">{{ formatMoney(calc.net, 'CZK') }}</div>
        <div class="text-xs text-neutral-500">{{ t('dashboard.tax_net_label') }}</div>
      </div>
      <div class="text-xs text-neutral-500 space-y-0.5">
        <div>{{ t('dashboard.tax_net_income') }}: <span class="font-mono text-neutral-700">{{ formatMoney(calc.income, 'CZK') }}</span></div>
        <div>{{ t('dashboard.tax_net_levies') }}: <span class="font-mono text-neutral-700">{{ formatMoney(calc.total, 'CZK') }}</span> ({{ (calc.eff * 100).toFixed(1) }} %)</div>
      </div>
    </div>
  </RouterLink>
</template>
