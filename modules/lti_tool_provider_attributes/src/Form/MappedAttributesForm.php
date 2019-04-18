<?php

namespace Drupal\lti_tool_provider_attributes\Form;

use Drupal;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for changing mapped_attributes config.
 */
class MappedAttributesForm extends FormBase
{

    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'lti_tool_provider_attributes_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state, $filter = '')
    {
        $user_attributes = [];
        $mapped_attributes = Drupal::configFactory()->getEditable('lti_tool_provider_attributes.settings')->get('mapped_attributes');

        $entityManager = Drupal::service('entity_field.manager');
        $userFieldDefinitions = $entityManager->getFieldDefinitions('user', 'user');

        /* @var $field FieldDefinitionInterface */
        foreach ($userFieldDefinitions as $key => $field) {
            $type = $field->getType();
            if ($type === 'string') {
                $user_attributes[$key] = t('@label (@name)', ['@label' => $field->getLabel(), '@name' => $key]);
            }
        }

        $form['mapped_attributes'] = [
            '#type' => 'table',
            '#tree' => true,
            '#caption' => t(
                'This page allows you to map LTI attrubutes to Drupal user attributes. This is applied every time a user logs in via LTI.'
            ),
            '#header' => [t('LTI Attribute'), t('User Field')],
        ];

        foreach ($mapped_attributes as $lti_attribute => $user_attribute) {
            $form['mapped_attributes'][$lti_attribute] = [
                'lti_attribute' => [
                    '#type' => 'item',
                    '#title' => $lti_attribute,
                ],
                'user_attribute' => [
                    '#type' => 'select',
                    '#required' => false,
                    '#empty_option' => t('None'),
                    '#empty_value' => true,
                    '#default_value' => $mapped_attributes[$lti_attribute],
                    '#options' => $user_attributes,
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
        $mapped_attributes = Drupal::configFactory()->getEditable('lti_tool_provider_attributes.settings')->get('mapped_attributes');

        foreach ($mapped_attributes as $lti_attribute => $user_attribute) {
            $mapped_attributes[$lti_attribute] = $form_state->getValue('mapped_attributes')[$lti_attribute]['user_attribute'];
        }

        Drupal::configFactory()->getEditable('lti_tool_provider_attributes.settings')->set('mapped_attributes', $mapped_attributes)->save();
        Drupal::messenger()->addMessage(t('LTI global attributes mapping saved.'));
    }

}
