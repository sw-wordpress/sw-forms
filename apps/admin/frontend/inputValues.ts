/** Keep phone values used by browser conditions aligned with server collection. */
export function phoneValue(value: string, country: string): string {
  const phone = value.trim();
  return country !== '' && phone !== '' && !/^(?:\+|00)/.test(phone) ? `${country} ${phone}` : phone;
}
