import { api } from './client'
import type { TaxConstantsData } from './tax'

/** Jeden rok v číselníku daňových konstant (efektivní data + zda jde o DB override). */
export interface TaxConstantsYear {
  year: number
  is_override: boolean
  data: TaxConstantsData
}

/**
 * Globální číselník ročních daňových konstant (admin). Override defaultů z
 * backendu (TaxConstants.php); reset = smazání override → návrat na default.
 */
export const taxConstantsApi = {
  list: () =>
    api.get<{ years: TaxConstantsYear[] }>('/codebooks/tax-constants').then(r => r.data.years),
  save: (year: number, data: TaxConstantsData) =>
    api.put<TaxConstantsYear>(`/codebooks/tax-constants/${year}`, { data }).then(r => r.data),
  reset: (year: number) =>
    api.delete<TaxConstantsYear>(`/codebooks/tax-constants/${year}`).then(r => r.data),
}
