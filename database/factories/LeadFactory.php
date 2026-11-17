<?php

namespace Database\Factories;

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Support\PublicIdGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Datos ficticios SOLO para pruebas automatizadas.
 * ⚠️ No usar en seeders de producción.
 *
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'public_id' => PublicIdGenerator::generate(fn (string $id) => Lead::where('public_id', $id)->exists()),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'phone' => '+504'.$this->faker->numerify('9#######'),
            'email' => $this->faker->unique()->safeEmail(),
            'business' => null,
            'service' => 'software-a-medida',
            'message' => $this->faker->sentence(12),
            'source' => 'prueba',
            'whatsapp_consent' => false,
            'whatsapp_consent_at' => null,
            'consent_version' => null,
            'status' => LeadStatus::Nueva,
            'status_changed_at' => now(),
        ];
    }

    public function status(LeadStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function anonymized(): static
    {
        return $this->state(fn () => [
            'first_name' => 'Anonimizado',
            'last_name' => 'Anonimizado',
            'email' => 'anonimizado-'.$this->faker->unique()->numerify('######').'@invalid.test',
            'phone' => '+50400000000',
            'business' => null,
            'message' => 'Contenido anonimizado.',
            'anonymized_at' => now(),
        ]);
    }

    /** Simula una solicitud antigua con consentimiento de WhatsApp (dato histórico). */
    public function withLegacyWhatsappConsent(): static
    {
        return $this->state(fn () => [
            'whatsapp_consent' => true,
            'whatsapp_consent_at' => now(),
            'consent_version' => 'whatsapp-v1',
        ]);
    }
}
