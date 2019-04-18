<?php

namespace Drupal\lti_tool_provider_roles\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for changing mapped_roles config.
 */
class MappedRolesForm extends FormBase
{

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'lti_tool_provider_roles_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state, $filter = '')
    {
        $user_roles = user_role_names(true);
        $mapped_roles = Drupal::configFactory()->getEditable('lti_tool_provider_roles.settings')->get('mapped_roles');

        $form['mapped_roles'] = [
            '#type' => 'table',
            '#tree' => true,
            '#caption' => t('This page allows you to map LTI roles to Drupal user roles. This is applied every time a user logs in via LTI.'),
            '#header' => [t('LTI Role'), t('User Role')],
        ];

        foreach ($mapped_roles as $lti_role => $user_role) {
            $form['mapped_roles'][$lti_role] = [
                'lti_role' => [
                    '#type' => 'item',
                    '#title' => $lti_role,
                ],
                'user_role' => [
                    '#type' => 'select',
                    '#required' => false,
                    '#empty_option' => t('None'),
                    '#empty_value' => true,
                    '#default_value' => $mapped_roles[$lti_role],
                    '#options' => $user_roles,
                ],
            ];
        }

        $form['submit'] = [
            '#type' => 'submit',
            '#value' => t('Save Mapped Roles'),
        ];

        return $form;
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $mapped_roles = Drupal::configFactory()->getEditable('lti_tool_provider_roles.settings')->get('mapped_roles');

        foreach ($mapped_roles as $lti_role => $user_role) {
            $mapped_roles[$lti_role] = $form_state->getValue('mapped_roles')[$lti_role]['user_role'];
        }

        Drupal::configFactory()->getEditable('lti_tool_provider_roles.settings')->set('mapped_roles', $mapped_roles)->save();
        Drupal::messenger()->addMessage(t('LTI global roles mapping saved.'));
    }
}
